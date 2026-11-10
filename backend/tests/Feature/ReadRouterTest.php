<?php

namespace Tests\Feature;

use App\Shared\Database\ReadRouter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * A router whose replica status is set by the test. The "replica" PDO is the
 * test database's own connection, so only the decision is tested here.
 */
class StubbedRouter extends ReadRouter
{
    public static int $statusQueries = 0;

    public ?array $replica = ['in_recovery' => true, 'replay_lsn' => '0/1000', 'lag_seconds' => 0.0];

    protected function fetchStatus(): ?array
    {
        self::$statusQueries++;
        if ($this->replica === null) {
            return parent::fetchStatus(); // the real path: connects and fails
        }

        return $this->replica + ['at' => microtime(true)];
    }
}

/**
 * Read routing (docs/performance.md, 6.6): after a write -> primary until the
 * replica catches up; no write -> replica; any doubt -> primary, never an error.
 */
class ReadRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['store.replica.store' => 'array']);
        Cache::store('array')->flush();
        StubbedRouter::$statusQueries = 0;
    }

    private function router(?callable $replicaFactory = null): StubbedRouter
    {
        $router = new StubbedRouter;
        $router->attach(DB::connection(), $replicaFactory ?? fn () => DB::connection()->getPdo());

        return $router;
    }

    private function signIn(string $userId): void
    {
        app('request')->attributes->set('user', ['id' => $userId, 'role' => 'customer']);
    }

    public function test_reads_go_to_the_replica_when_nothing_requires_the_primary(): void
    {
        $router = $this->router();
        $router->resolveReadPdo();

        $this->assertSame(ReadRouter::REPLICA, $router->target());
    }

    public function test_after_a_write_reads_stay_on_the_primary_until_the_replica_catches_up(): void
    {
        $this->signIn('user-1');
        Cache::store('array')->put('ryw:user:user-1', '0/2000', 300); // this user's write

        $behind = $this->router();
        $behind->replica['replay_lsn'] = '0/1FFF';
        $behind->resolveReadPdo();
        $this->assertSame(ReadRouter::PRIMARY, $behind->target(), 'replica has not replayed the write');

        Cache::store('array')->forget('db:replica-status');
        $caughtUp = $this->router();
        $caughtUp->replica['replay_lsn'] = '0/2000';
        $caughtUp->resolveReadPdo();
        $this->assertSame(ReadRouter::REPLICA, $caughtUp->target(), 'replica is at the write');

        // Another user without a write is not affected.
        $this->signIn('user-2');
        Cache::store('array')->forget('db:replica-status');
        $other = $this->router();
        $other->replica['replay_lsn'] = '0/1FFF';
        $other->resolveReadPdo();
        $this->assertSame(ReadRouter::REPLICA, $other->target());
    }

    public function test_redis_down_means_primary_never_an_error(): void
    {
        config([
            'store.replica.store' => 'catalog', // the real Redis store...
            'database.redis.catalog.host' => '127.0.0.1',
            'database.redis.catalog.port' => 1, // ...where nothing listens
        ]);
        Log::spy();
        $this->signIn('user-1');

        $router = $this->router();
        $router->resolveReadPdo();

        $this->assertSame(ReadRouter::PRIMARY, $router->target());
        $this->assertFalse($router->replicaHasReached('0/1'));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_a_down_replica_means_primary_and_is_skipped_for_a_while(): void
    {
        Log::spy();
        $attempts = 0;
        $factory = function () use (&$attempts) {
            $attempts++;
            throw new PDOException('could not connect to db_replica');
        };

        $first = $this->router($factory);
        $first->replica = null; // real status path: tries to connect
        $first->resolveReadPdo();
        $this->assertSame(ReadRouter::PRIMARY, $first->target());
        $this->assertSame(1, $attempts);
        $this->assertTrue((bool) Cache::store('array')->get('db:replica-down'));

        // The next requests don't even try while the replica is marked down.
        $second = $this->router($factory);
        $second->replica = null;
        $second->resolveReadPdo();
        $this->assertSame(ReadRouter::PRIMARY, $second->target());
        $this->assertSame(1, $attempts);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'read replica unavailable'))->once();
    }

    public function test_a_replica_lagging_above_the_threshold_is_not_used(): void
    {
        config(['store.replica.max_lag_seconds' => 5]);
        $router = $this->router();
        $router->replica['lag_seconds'] = 7.5;

        $router->resolveReadPdo();

        $this->assertSame(ReadRouter::PRIMARY, $router->target());
    }

    public function test_the_replica_position_is_checked_once_for_many_requests(): void
    {
        foreach (range(1, 5) as $request) {
            $this->router()->resolveReadPdo();
        }

        $this->assertSame(1, StubbedRouter::$statusQueries, 'shared for status_ttl_ms, not one query per request');
    }

    public function test_lsn_comparison_is_numeric_not_textual(): void
    {
        $this->assertSame(0, ReadRouter::compareLsn('16/B374D848', '16/B374D848'));
        $this->assertSame(1, ReadRouter::compareLsn('2/0', '1/FFFFFFFF'));
        $this->assertSame(-1, ReadRouter::compareLsn('0/9', '0/10'), '"0/9" > "0/10" as text, < as positions');
        $this->assertSame(1, ReadRouter::compareLsn('A/0', '9/FFFFFFFF'));
    }

    public function test_laravel_reads_on_the_primary_inside_a_transaction_and_after_a_write(): void
    {
        // A connection with a separate read PDO and sticky, like production
        // (both point at the test database here).
        $base = config('database.connections.pgsql');
        config(['database.connections.pgsql_rw' => $base + [
            'read' => ['host' => [$base['host']]],
            'write' => ['host' => [$base['host']]],
            'sticky' => true,
        ]]);

        $fresh = DB::connection('pgsql_rw');
        $this->assertNotSame($fresh->getPdo(), $fresh->getReadPdo(), 'plain reads use the read connection');

        DB::purge('pgsql_rw');
        $inTransaction = DB::connection('pgsql_rw');
        $inTransaction->transaction(function () use ($inTransaction): void {
            $this->assertSame($inTransaction->getPdo(), $inTransaction->getReadPdo(), 'transaction: primary');
        });

        DB::purge('pgsql_rw');
        $afterWrite = DB::connection('pgsql_rw');
        $afterWrite->statement('CREATE TEMP TABLE ryw_probe (x int)');
        $this->assertSame($afterWrite->getPdo(), $afterWrite->getReadPdo(), 'sticky: primary after a write');
        DB::purge('pgsql_rw');
    }
}
