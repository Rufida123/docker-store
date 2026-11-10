<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Products\ProductsModule;
use App\Modules\Products\ProductsService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Inventory double: fixed stock levels, counts GetStock calls.
 */
class CountingInventory extends InventoryModule
{
    public int $getStockCalls = 0;

    public array $levels = [];

    public function getStock(array $productIds): array
    {
        $this->getStockCalls++;

        // Known levels first: with "+" the left-hand keys win.
        return array_intersect_key($this->levels, array_flip($productIds)) + array_map(fn () => 0, array_flip($productIds));
    }

    public function adjust(string $productId, int $change): array
    {
        $this->levels[$productId] = ($this->levels[$productId] ?? 0) + $change;

        return ['status' => 'applied', 'available' => $this->levels[$productId]];
    }
}

/**
 * The catalog cache (docs/performance.md, 6.4): fresh after an edit, nothing
 * cached from a rolled-back edit, normalized keys, display stock invalidated
 * on stock changes, and the API keeps working when Redis is down.
 *
 * DatabaseTruncation, not RefreshDatabase: the cache is bypassed inside a
 * database transaction, and RefreshDatabase would run every test inside one.
 */
class ProductsCacheTest extends TestCase
{
    use DatabaseTruncation;

    private CountingInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['store.catalog_cache.store' => 'array']);
        Cache::store('array')->flush();
        $this->inventory = new CountingInventory;
        $this->app->instance(InventoryModule::class, $this->inventory);
    }

    private function makeProduct(string $price = '10.00', string $category = 'home'): string
    {
        $id = (string) Str::uuid();
        DB::insert('INSERT INTO products (id, name, category, price) VALUES (?, ?, ?, ?)', [$id, 'Lamp', $category, $price]);

        return $id;
    }

    private function service(): ProductsService
    {
        return app(ProductsService::class);
    }

    private function queriesDuring(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_an_edit_is_visible_on_the_next_read(): void
    {
        $id = $this->makeProduct('10.00');
        $this->assertSame('10.00', $this->service()->getProduct($id)['price']);
        $this->assertSame('10.00', $this->service()->listProducts([])['data'][0]['price']);

        $this->service()->updateProduct($id, ['price' => 12.5, 'name' => 'Desk Lamp']);

        $product = $this->service()->getProduct($id);
        $this->assertSame('12.50', $product['price']);
        $this->assertSame('Desk Lamp', $product['name']);
        $this->assertSame('12.50', $this->service()->listProducts([])['data'][0]['price']);
    }

    public function test_a_rolled_back_edit_leaves_no_stale_data_in_the_cache(): void
    {
        $id = $this->makeProduct('10.00');
        $this->service()->getProduct($id);     // cached: 10.00
        $this->service()->listProducts([]);    // cached page + count

        try {
            DB::transaction(function () use ($id): void {
                $this->service()->updateProduct($id, ['price' => 99]);
                // Inside the transaction the cache is bypassed: the uncommitted
                // row is read from the database but never stored.
                $this->assertSame('99.00', $this->service()->getProduct($id)['price']);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('10.00', $this->service()->getProduct($id)['price']);
        $this->assertSame('10.00', $this->service()->listProducts([])['data'][0]['price']);
        $this->assertSame('10.00', Cache::store('array')->get('products:item:'.$id)['price']);
        $this->assertNull(Cache::store('array')->get('products:catalog-version'), 'no invalidation for a rolled-back edit');
    }

    public function test_equivalent_requests_share_one_cache_entry(): void
    {
        $this->makeProduct();
        $this->service()->listProducts(['category' => 'home']);

        // Same page after normalization: explicit defaults, case and spaces in
        // the category, unknown parameters. Served without a single query.
        $queries = $this->queriesDuring(function (): void {
            $this->service()->listProducts(['page' => '1', 'limit' => '20', 'category' => ' Home ', 'utm' => 'x']);
        });
        $this->assertSame(0, $queries);

        // A different input is a different entry (the count is shared).
        $queries = $this->queriesDuring(fn () => $this->service()->listProducts(['category' => 'home', 'page' => '2']));
        $this->assertSame(1, $queries, 'page 2 reads its rows; the category count comes from the cache');
    }

    public function test_display_stock_is_dropped_when_the_inventory_changes_it(): void
    {
        $id = $this->makeProduct();
        $this->inventory->levels[$id] = 5;

        $this->assertSame(5, $this->service()->getProduct($id)['stock']);
        $this->service()->getProduct($id);
        $this->assertSame(1, $this->inventory->getStockCalls, 'second read from the display-stock cache');

        // A reservation (orders module) took 2.
        $this->inventory->levels[$id] = 3;
        app(ProductsModule::class)->stockChanged([$id]);

        $this->assertSame(3, $this->service()->getProduct($id)['stock']);
        $this->assertSame(2, $this->inventory->getStockCalls);

        // An admin restock (AdjustStock) is visible at once too.
        $this->service()->changeStock($id, ['change' => 10]);
        $this->assertSame(13, $this->service()->getProduct($id)['stock']);
    }

    public function test_a_new_product_appears_in_the_cached_catalog(): void
    {
        $this->makeProduct();
        $this->assertSame(1, $this->service()->listProducts([])['pagination']['total']);

        $this->service()->createProduct(['name' => 'Chair', 'price' => 40], null);

        $page = $this->service()->listProducts([]);
        $this->assertSame(2, $page['pagination']['total']);
        $this->assertSame('Chair', $page['data'][0]['name']);
    }

    public function test_the_api_keeps_working_from_the_database_when_redis_is_down(): void
    {
        // The real Redis store, pointed at a port where nothing listens.
        config([
            'store.catalog_cache.store' => 'catalog',
            'database.redis.catalog.host' => '127.0.0.1',
            'database.redis.catalog.port' => 1,
        ]);
        Log::spy();
        $id = $this->makeProduct('10.00');

        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/products/'.$id)->assertOk()->assertJsonPath('price', '10.00');

        // Writes still work; their invalidation failure is only logged.
        $this->service()->updateProduct($id, ['price' => 11]);
        $this->getJson('/api/v1/products/'.$id)->assertOk()->assertJsonPath('price', '11.00');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'catalog cache'))->atLeast()->once();
    }
}
