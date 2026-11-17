<?php

namespace App\Shared\Database;

use App\Shared\PerfCounters;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Chooses, per request, whether reads go to the replica or the primary
 * (docs/performance.md, 6.6). Laravel already sends to the primary every
 * write, every read inside a transaction, and every read after a write in the
 * same request (sticky). This class decides the rest, lazily, on the first
 * read of the request:
 *
 * - Read-your-writes across requests: after a user's write, RecordWrites
 *   stores the primary's WAL position (LSN) under ryw:user:{id}. That user's
 *   reads use the replica only once the replica has replayed past it.
 * - The replica is not used when it is down (then skipped for a few seconds by
 *   every request), when its lag is above a threshold, or when the check
 *   itself fails (Redis down, ...). Every failure means "primary", never an
 *   error.
 * - The replica's position is read at most every ~200 ms, shared by all
 *   requests through the cache, not on every request.
 */
class ReadRouter
{
    public const REPLICA = 'replica';

    public const PRIMARY = 'primary';

    private const STATUS_KEY = 'db:replica-status';

    private const DOWN_KEY = 'db:replica-down';

    private ?Connection $connection = null;

    private ?Closure $replicaFactory = null;

    private ?PDO $replicaPdo = null;

    /** Where this request's reads went (null until the first read). */
    private ?string $target = null;

    private bool $warnedLag = false;

    /**
     * Called once at boot when a read host is configured: $replicaFactory is
     * Laravel's own resolver for the read connection.
     */
    public function attach(Connection $connection, Closure $replicaFactory): void
    {
        $this->connection = $connection;
        $this->replicaFactory = $replicaFactory;
        $connection->setReadPdo(fn () => $this->resolveReadPdo());
    }

    public function enabled(): bool
    {
        return $this->connection !== null;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    // ---- the decision ----

    /** Laravel calls this on the request's first read that may use a replica. */
    public function resolveReadPdo(): PDO
    {
        $started = hrtime(true);
        try {
            $userId = $this->currentUserId();
            $target = $this->targetFor($userId === null ? null : $this->store()->get('ryw:user:'.$userId));
        } catch (Throwable $e) {
            Log::warning('read routing check failed, reading from the primary: '.$e->getMessage());
            $target = self::PRIMARY;
        }

        $pdo = $target === self::REPLICA ? $this->replicaOrNull() : null;
        if ($pdo === null) {
            $target = self::PRIMARY;
            $pdo = $this->connection->getPdo();
        }

        $this->target = $target;
        PerfCounters::add('db_read_'.$target);
        // The decision's own cost: Redis lookups and, when the shared status
        // is stale, the status query. The replica connect is counted apart.
        PerfCounters::add('route_us', max(0, intdiv(hrtime(true) - $started, 1000) - PerfCounters::get('replica_connect_us')));

        return $pdo;
    }

    /**
     * May a read that has to see the write at $lsn use the replica right now?
     * False on any doubt. Without a replica there is nothing to wait for.
     */
    public function replicaHasReached(?string $lsn): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        try {
            return $this->targetFor($lsn) === self::REPLICA;
        } catch (Throwable $e) {
            Log::warning('replica position check failed, using the primary: '.$e->getMessage());

            return false;
        }
    }

    private function targetFor(?string $requiredLsn): string
    {
        if ($this->store()->get(self::DOWN_KEY)) {
            return self::PRIMARY;
        }

        $status = $this->status();
        if ($status === null || ! $status['in_recovery']) {
            return self::PRIMARY;
        }
        if ($status['lag_seconds'] > (float) config('store.replica.max_lag_seconds')) {
            if (! $this->warnedLag) {
                $this->warnedLag = true;
                Log::warning(sprintf('replica lag %.1f s is above the limit, reading from the primary', $status['lag_seconds']));
            }

            return self::PRIMARY;
        }
        if ($requiredLsn !== null && self::compareLsn($status['replay_lsn'], $requiredLsn) < 0) {
            return self::PRIMARY; // the replica has not replayed this user's write yet
        }

        return self::REPLICA;
    }

    /**
     * The replica's replay position and lag, shared by every request for
     * status_ttl_ms. Null when the replica can't be reached.
     *
     * @return array{in_recovery: bool, replay_lsn: string, lag_seconds: float, at: float}|null
     */
    protected function status(): ?array
    {
        $cached = $this->store()->get(self::STATUS_KEY);
        if (is_array($cached) && (microtime(true) - $cached['at']) * 1000 < (int) config('store.replica.status_ttl_ms')) {
            return $cached;
        }

        $status = $this->fetchStatus();
        if ($status !== null) {
            $this->store()->put(self::STATUS_KEY, $status, 5);
        }

        return $status;
    }

    /**
     * One query on the replica. Lag counts only while WAL is waiting to be
     * replayed: an idle replica that has replayed everything has no lag, even
     * if its last replayed transaction is old.
     */
    protected function fetchStatus(): ?array
    {
        $pdo = $this->replicaOrNull();
        if ($pdo === null) {
            return null;
        }
        $row = $pdo->query(
            "SELECT pg_is_in_recovery() AS in_recovery,
                    pg_last_wal_replay_lsn()::text AS replay_lsn,
                    CASE WHEN pg_last_wal_receive_lsn() IS DISTINCT FROM pg_last_wal_replay_lsn()
                         THEN COALESCE(EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp()), 0)
                         ELSE 0 END AS lag_seconds"
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'in_recovery' => (bool) $row['in_recovery'],
            'replay_lsn' => (string) ($row['replay_lsn'] ?? '0/0'),
            'lag_seconds' => (float) $row['lag_seconds'],
            'at' => microtime(true),
        ];
    }

    /** The replica connection, or null (logged, and skipped by everyone for a while). */
    private function replicaOrNull(): ?PDO
    {
        if ($this->replicaPdo !== null) {
            return $this->replicaPdo;
        }
        try {
            // Opening the connection is the cost of reading the replica at
            // all, not of the routing decision: measured apart.
            $connecting = hrtime(true);
            $this->replicaPdo = ($this->replicaFactory)();
            PerfCounters::add('replica_connect_us', intdiv(hrtime(true) - $connecting, 1000));

            return $this->replicaPdo;
        } catch (Throwable $e) {
            Log::warning('read replica unavailable, reading from the primary: '.$e->getMessage());
            try {
                $this->store()->put(self::DOWN_KEY, true, (int) config('store.replica.down_seconds'));
            } catch (Throwable) {
                // Redis down too: the next request tries the replica again.
            }

            return null;
        }
    }

    // ---- writes ----

    /** The primary's current WAL position (after a commit: includes that commit). */
    public function primaryLsn(): string
    {
        return $this->connection->selectFromWriteConnection('SELECT pg_current_wal_lsn()::text AS lsn')[0]->lsn;
    }

    /** RecordWrites: this user's next reads must see what was just committed. */
    public function recordUserWrite(string $userId): void
    {
        $this->store()->put('ryw:user:'.$userId, $this->primaryLsn(), (int) config('store.replica.write_lsn_ttl'));
    }

    // ---- helpers ----

    private function store(): Repository
    {
        return Cache::store((string) config('store.replica.store'));
    }

    private function currentUserId(): ?string
    {
        $user = app('request')->attributes->get('user');

        return is_array($user) && is_string($user['id'] ?? null) ? $user['id'] : null;
    }

    /** pg_lsn "16/B374D848" compared as a 64-bit position. */
    public static function compareLsn(string $a, string $b): int
    {
        return self::lsnToInt($a) <=> self::lsnToInt($b);
    }

    private static function lsnToInt(string $lsn): int
    {
        [$high, $low] = array_pad(explode('/', $lsn, 2), 2, '0');

        return (hexdec($high) << 32) | hexdec($low);
    }
}
