<?php

namespace App\Modules\Products;

use App\Shared\Database\ReadRouter;
use App\Shared\PerfCounters;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catalog cache of the products module (docs/performance.md, 6.4). Only this
 * module uses it; values are plain arrays/ints, never objects.
 *
 *   product detail  products:item:{id}                       flexible 10 min fresh / 20 min stale
 *   catalog page    products:list:v{ver}:c={cat}:l={limit}:p={page}   flexible 2 min / 10 min
 *   catalog count   products:count:v{ver}:c={cat}                     flexible 2 min / 10 min
 *   display stock   products:stock:{id}                      5 s, plain put
 *   catalog version products:catalog-version                 INCR, no TTL
 *
 * Rules:
 * - Writes invalidate AFTER the database commit (DB::afterCommit), never
 *   before, so a reader can't re-cache the old row between the invalidation
 *   and the commit, and a rolled-back write invalidates nothing.
 * - Inside a database transaction the cache is bypassed (no reads, no
 *   writes), so uncommitted data is never cached.
 * - Every cache failure (Redis down, timeout) is logged once per request and
 *   the value is computed from the database: the cache can make requests
 *   faster, never make them fail.
 */
class ProductsCache
{
    private const PRODUCT_TTL = [600, 1200];

    private const LIST_TTL = [120, 600];

    private const STOCK_TTL = 5;

    private const LOCK_SECONDS = 10; // a crashed holder frees the lock after this

    private const LOCK_WAIT = 3;     // a waiter computes itself after this

    private const VERSION_KEY = 'products:catalog-version';

    /** Set after the first failure: the rest of the request skips the cache. */
    private bool $down = false;

    private const LAST_WRITE_LSN_KEY = 'products:last-write-lsn';

    public function __construct(private ReadRouter $router)
    {
    }

    private function store(): Repository
    {
        return Cache::store((string) config('store.catalog_cache.store'));
    }

    private function usable(): bool
    {
        return ! $this->down && DB::transactionLevel() === 0;
    }

    /**
     * Runs a cache operation; on failure logs once, stops using the cache
     * for this request and returns the fallback.
     */
    private function attempt(callable $operation, callable $fallback): mixed
    {
        if (! $this->usable()) {
            return $fallback();
        }

        try {
            return $operation();
        } catch (Throwable $e) {
            if (! $this->down) {
                $this->down = true;
                Log::warning('catalog cache unavailable, serving from the database: '.$e->getMessage());
            }

            return $fallback();
        }
    }

    /**
     * Stale-while-revalidate (Cache::flexible) for expiry, plus a lock for the
     * cold miss: flexible alone lets every concurrent request compute a
     * missing key (after an invalidation, all of them at once).
     */
    private function flexible(string $key, array $ttl, callable $load): mixed
    {
        return $this->attempt(function () use ($key, $ttl, $load) {
            $computed = false;
            $value = $this->store()->flexible(
                $key,
                $ttl,
                function () use ($key, $load, &$computed) {
                    $computed = true;

                    return $this->computeOnce($key, $load);
                },
                ['seconds' => self::LOCK_SECONDS],
            );
            PerfCounters::add($computed ? 'cache_miss' : 'cache_hit');

            return $value;
        }, fn () => $this->onPrimary($load));
    }

    /**
     * One request computes a missing key; the others wait for it and read
     * its result. If the wait is too long, the waiter computes it itself.
     */
    private function computeOnce(string $key, callable $load): mixed
    {
        try {
            return $this->store()->lock('products:lock:'.$key, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT, function () use ($key, $load) {
                    return $this->store()->get($key) ?? $this->refill($load);
                });
        } catch (LockTimeoutException) {
            return $this->refill($load);
        }
    }

    /**
     * Refills a missing or expired entry. The trap this avoids: right after
     * a product write the entry is invalidated, and a refill read from a
     * replica that has not replayed the write yet would cache the OLD row for
     * the whole TTL. So the refill uses the replica only once it has replayed
     * past the last product write (LSN stored after commit); otherwise, or on
     * any doubt, the primary. Most refills still go to the replica: the check
     * is against the last product write, not "always primary".
     */
    private function refill(callable $load): mixed
    {
        $lsn = $this->store()->get(self::LAST_WRITE_LSN_KEY);
        if ($lsn !== null && ! $this->router->replicaHasReached($lsn)) {
            PerfCounters::add('refill_primary');

            return $this->onPrimary($load);
        }

        $value = $load();
        PerfCounters::add('refill_'.($this->router->target() ?? ReadRouter::PRIMARY));

        return $value;
    }

    private static int $primaryDepth = 0;

    /** Runs $load with every read on the primary (nesting-safe). */
    private function onPrimary(callable $load): mixed
    {
        $connection = DB::connection();
        self::$primaryDepth++;
        $connection->useWriteConnectionWhenReading(true);
        try {
            return $load();
        } finally {
            if (--self::$primaryDepth === 0) {
                $connection->useWriteConnectionWhenReading(false);
            }
        }
    }

    private function catalogVersion(): int
    {
        return (int) ($this->store()->get(self::VERSION_KEY) ?? 0);
    }

    private static function categoryPart(?string $category): string
    {
        // Category is already validated, trimmed and lower-cased by the
        // service; rawurlencode keeps the key readable and unambiguous.
        return $category === null ? '*' : rawurlencode($category);
    }

    // ---- reads ----

    /** @param callable(): ?array $load */
    public function product(string $id, callable $load): ?array
    {
        return $this->flexible('products:item:'.$id, self::PRODUCT_TTL, $load);
    }

    /**
     * One catalog page. The key holds every input that changes the result
     * (category, limit, page) after normalization, and the catalog version.
     *
     * @param callable(): array $load
     */
    public function page(?string $category, int $limit, int $page, callable $load): array
    {
        return $this->attempt(function () use ($category, $limit, $page, $load) {
            $key = sprintf('products:list:v%d:c=%s:l=%d:p=%d', $this->catalogVersion(), self::categoryPart($category), $limit, $page);

            return $this->flexible($key, self::LIST_TTL, $load);
        }, fn () => $this->onPrimary($load));
    }

    /** @param callable(): int $load */
    public function count(?string $category, callable $load): int
    {
        return (int) $this->attempt(function () use ($category, $load) {
            $key = sprintf('products:count:v%d:c=%s', $this->catalogVersion(), self::categoryPart($category));

            return $this->flexible($key, self::LIST_TTL, $load);
        }, fn () => $this->onPrimary($load));
    }

    /**
     * Display stock for several products: cached ones from Redis, the rest
     * from $load (one batched GetStock) and cached for a few seconds. Only
     * for display: reservations always go to the inventory service.
     *
     * @param list<string> $ids
     * @param callable(list<string>): array<string, int> $load  throws when the inventory is down
     * @return array<string, int>
     */
    public function stock(array $ids, callable $load): array
    {
        if ($ids === []) {
            return [];
        }

        $cached = $this->attempt(
            fn () => $this->store()->many(array_map(fn (string $id) => 'products:stock:'.$id, $ids)),
            fn () => [],
        );

        $levels = [];
        $missing = [];
        foreach ($ids as $id) {
            $value = $cached['products:stock:'.$id] ?? null;
            if ($value === null) {
                $missing[] = $id;
            } else {
                $levels[$id] = (int) $value;
            }
        }
        PerfCounters::add('cache_hit', count($levels));

        if ($missing !== []) {
            PerfCounters::add('cache_miss', count($missing));
            $loaded = $load($missing); // an inventory failure propagates to the caller
            $fresh = [];
            foreach ($missing as $id) {
                $levels[$id] = (int) ($loaded[$id] ?? 0);
                $fresh['products:stock:'.$id] = $levels[$id];
            }
            $this->attempt(fn () => $this->store()->putMany($fresh, self::STOCK_TTL), fn () => null);
        }

        return $levels;
    }

    // ---- invalidation (always after commit) ----

    /** A product was created or changed (fields, image): drop it and every catalog page. */
    public function productChanged(?string $id, ?callable $freshRow = null): void
    {
        DB::afterCommit(function () use ($id, $freshRow): void {
            $this->invalidate(function () use ($id, $freshRow): void {
                // 1. Remember where this write is in the WAL: refills wait for
                //    the replica to pass it (see refill()).
                if ($this->router->enabled()) {
                    $this->store()->forever(self::LAST_WRITE_LSN_KEY, $this->router->primaryLsn());
                }
                // 2. Write-through: the edited product is read from the
                //    primary and stored at once, instead of leaving a hole a
                //    reader could fill from a lagging replica.
                if ($id !== null) {
                    $key = 'products:item:'.$id;
                    $fresh = $freshRow === null ? null : $this->onPrimary($freshRow);
                    if ($fresh === null) {
                        $this->store()->forget($key);
                    } else {
                        $this->store()->putMany([
                            $key => $fresh,
                            // flexible()'s own "created at" entry, so the
                            // value counts as fresh for its normal TTL.
                            \Illuminate\Cache\Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key => time(),
                        ], self::PRODUCT_TTL[1]);
                    }
                }
                // 3. Catalog pages: new version, guarded by the LSN of step 1.
                // Atomic INCR (Redis INCRBY), no TTL: every page and count key
                // embeds the version, so all of them are bypassed at once.
                $this->store()->increment(self::VERSION_KEY);
            });
        });
    }

    /**
     * Stock of these products changed (AdjustStock, reserve, release): drop
     * their display stock so the next read asks the inventory.
     *
     * @param list<string> $ids
     */
    public function stockChanged(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        DB::afterCommit(function () use ($ids): void {
            $this->invalidate(function () use ($ids): void {
                $this->store()->deleteMultiple(array_map(fn (string $id) => 'products:stock:'.$id, $ids));
            });
        });
    }

    /**
     * Invalidation ignores the per-request "down" flag (a failed read should
     * not skip a later delete) but still never throws: a failed delete is
     * logged and the entry expires with its TTL.
     */
    private function invalidate(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            Log::warning('catalog cache invalidation failed, entries will expire with their TTL: '.$e->getMessage());
        }
    }
}
