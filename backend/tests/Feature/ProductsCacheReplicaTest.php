<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Products\ProductsService;
use App\Shared\Database\ReadRouter;
use App\Shared\PerfCounters;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A replica that is (or is not) past the last product write.
 */
class LaggingReplicaRouter extends ReadRouter
{
    public bool $caughtUp = false;

    public array $askedFor = [];

    public function enabled(): bool
    {
        return true;
    }

    public function primaryLsn(): string
    {
        return '0/5000';
    }

    public function replicaHasReached(?string $lsn): bool
    {
        $this->askedFor[] = $lsn;

        return $this->caughtUp;
    }

    public function target(): ?string
    {
        return self::REPLICA; // reads that are not forced go to the replica
    }
}

class StockFive extends InventoryModule
{
    public function getStock(array $productIds): array
    {
        return array_fill_keys($productIds, 5);
    }
}

/**
 * The cache + replica trap (docs/performance.md, 6.6): after a product write
 * a cache refill must not read a replica that has not replayed it yet, or the
 * old row is cached for the whole TTL.
 */
class ProductsCacheReplicaTest extends TestCase
{
    use DatabaseTruncation;

    private LaggingReplicaRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        config(['store.catalog_cache.store' => 'array']);
        Cache::store('array')->flush();
        PerfCounters::reset();
        $this->router = new LaggingReplicaRouter;
        $this->app->instance(ReadRouter::class, $this->router);
        $this->app->instance(InventoryModule::class, new StockFive);
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO products (id, name, category, price) VALUES (?, 'Lamp', 'home', 10.00)", [$id]);

        return $id;
    }

    public function test_an_edit_writes_the_fresh_row_through_and_records_its_lsn(): void
    {
        $id = $this->makeProduct();
        $service = app(ProductsService::class);
        $service->getProduct($id); // cached: 10.00

        $service->updateProduct($id, ['price' => 12]);

        $this->assertSame('0/5000', Cache::store('array')->get('products:last-write-lsn'));
        $this->assertSame('12.00', Cache::store('array')->get('products:item:'.$id)['price'], 'write-through, not a hole');

        // The next read is a cache hit: no refill at all, so no replica involved.
        PerfCounters::reset();
        $this->assertSame('12.00', $service->getProduct($id)['price']);
        $this->assertSame(0, PerfCounters::get('refill_replica') + PerfCounters::get('refill_primary'));
    }

    public function test_a_refill_reads_the_primary_while_the_replica_is_behind_the_last_write(): void
    {
        $this->makeProduct();
        $service = app(ProductsService::class);
        $service->updateProduct($this->makeProduct(), ['price' => 11]); // last write LSN 0/5000

        // The catalog version moved: the page is a miss and must be refilled.
        $this->router->caughtUp = false;
        PerfCounters::reset();
        $service->listProducts([]);
        $this->assertGreaterThan(0, PerfCounters::get('refill_primary'));
        $this->assertSame(0, PerfCounters::get('refill_replica'));
        $this->assertContains('0/5000', $this->router->askedFor, 'compared against the last product write');

        // Once the replica has replayed past it, refills use the replica again.
        $this->router->caughtUp = true;
        PerfCounters::reset();
        $service->listProducts(['page' => '2']);
        $this->assertGreaterThan(0, PerfCounters::get('refill_replica'));
        $this->assertSame(0, PerfCounters::get('refill_primary'));
    }

    public function test_without_any_product_write_refills_never_wait_for_the_replica(): void
    {
        $this->makeProduct();
        PerfCounters::reset();

        app(ProductsService::class)->listProducts([]);

        $this->assertSame([], $this->router->askedFor, 'no last write: no position check');
        $this->assertGreaterThan(0, PerfCounters::get('refill_replica'));
    }
}
