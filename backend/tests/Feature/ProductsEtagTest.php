<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Shared\Http\Middleware\ETagRevalidation;
use App\Shared\InventoryUnavailable;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Inventory double for the ETag tests: settable levels, can be "down".
 */
class EtagInventory extends InventoryModule
{
    public array $levels = [];

    public bool $down = false;

    public function getStock(array $productIds): array
    {
        if ($this->down) {
            throw new InventoryUnavailable('inventory is down (test)');
        }

        return array_intersect_key($this->levels, array_flip($productIds)) + array_map(fn () => 0, array_flip($productIds));
    }

    public function adjust(string $productId, int $change): array
    {
        $this->levels[$productId] = ($this->levels[$productId] ?? 0) + $change;

        return ['status' => 'applied', 'available' => $this->levels[$productId]];
    }
}

/**
 * ETag / 304 on GET /products and GET /products/{id} (docs/performance.md, 6.5).
 * DatabaseTruncation so the catalog cache is active, like in production.
 */
class ProductsEtagTest extends TestCase
{
    use DatabaseTruncation;

    private EtagInventory $inventory;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['store.catalog_cache.store' => 'array']);
        Cache::store('array')->flush();
        $this->inventory = new EtagInventory;
        $this->app->instance(InventoryModule::class, $this->inventory);

        $this->productId = (string) Str::uuid();
        DB::insert("INSERT INTO products (id, name, category, price) VALUES (?, 'Lamp', 'home', 10.00)", [$this->productId]);
        $this->inventory->levels[$this->productId] = 5;
    }

    private function adminToken(): string
    {
        $now = time();

        return JWT::encode(
            ['sub' => (string) Str::uuid(), 'role' => 'admin', 'iat' => $now, 'exp' => $now + 600],
            (string) config('store.jwt.secret'),
            'HS256',
        );
    }

    private function getWith(string $url, ?string $ifNoneMatch = null): TestResponse
    {
        return $this->get($url, $ifNoneMatch === null ? [] : ['If-None-Match' => $ifNoneMatch]);
    }

    private function detailUrl(): string
    {
        return '/api/v1/products/'.$this->productId;
    }

    public function test_a_first_request_gets_200_with_an_etag_and_no_cache(): void
    {
        $response = $this->getWith($this->detailUrl());

        $response->assertOk()->assertJsonPath('price', '10.00');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $response->headers->get('ETag'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);
    }

    public function test_the_same_etag_gets_304_with_an_empty_body(): void
    {
        $etag = $this->getWith($this->detailUrl())->headers->get('ETag');

        $response = $this->getWith($this->detailUrl(), $etag);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_an_admin_edit_gives_200_new_data_and_a_new_etag(): void
    {
        $old = $this->getWith($this->detailUrl())->headers->get('ETag');

        $this->patchJson($this->detailUrl(), ['price' => 12.5], ['Authorization' => 'Bearer '.$this->adminToken()])->assertOk();

        $response = $this->getWith($this->detailUrl(), $old);
        $response->assertOk()->assertJsonPath('price', '12.50');
        $new = $response->headers->get('ETag');
        $this->assertNotSame($old, $new);
        $this->getWith($this->detailUrl(), $new)->assertStatus(304);
    }

    public function test_a_stock_change_gives_a_new_etag(): void
    {
        $old = $this->getWith($this->detailUrl())->headers->get('ETag');
        $listOld = $this->getWith('/api/v1/products')->headers->get('ETag');

        $this->patchJson($this->detailUrl().'/stock', ['change' => 3], ['Authorization' => 'Bearer '.$this->adminToken()])->assertOk();

        $response = $this->getWith($this->detailUrl(), $old);
        $response->assertOk()->assertJsonPath('stock', 8);
        $this->assertNotSame($old, $response->headers->get('ETag'));
        $this->getWith('/api/v1/products', $listOld)->assertOk(); // the page shows the stock too
    }

    public function test_inventory_unavailable_and_available_give_different_etags(): void
    {
        $available = $this->getWith($this->detailUrl());
        Cache::store('array')->flush(); // drop the 5 s display stock
        $this->inventory->down = true;

        $unavailable = $this->getWith($this->detailUrl(), $available->headers->get('ETag'));

        $unavailable->assertOk()->assertJsonPath('stock_status', 'unavailable');
        $this->assertNotSame($available->headers->get('ETag'), $unavailable->headers->get('ETag'));
    }

    public function test_different_pages_categories_and_limits_give_different_etags(): void
    {
        // Two categories and more than one page at limit=1, so every query
        // below has a different body. (Two queries with the same body share
        // an ETag, correctly: it identifies the representation.)
        DB::insert("INSERT INTO products (id, name, category, price) VALUES (?, 'Rake', 'garden', 7.00)", [(string) Str::uuid()]);

        $etags = [];
        foreach (['', '?page=2&limit=1', '?category=home', '?category=garden', '?limit=1'] as $query) {
            $etags[$query] = $this->getWith('/api/v1/products'.$query)->assertOk()->headers->get('ETag');
        }

        $this->assertCount(5, array_unique($etags));
        // An equivalent request is the same resource state: same ETag.
        $this->assertSame($etags[''], $this->getWith('/api/v1/products?page=1&limit=20')->headers->get('ETag'));
    }

    public function test_errors_never_get_an_etag_or_a_304(): void
    {
        $missing = '/api/v1/products/'.Str::uuid();
        foreach ([null, '*'] as $ifNoneMatch) {
            $response = $this->getWith($missing, $ifNoneMatch);
            $response->assertNotFound();
            $this->assertFalse($response->headers->has('ETag'));
        }

        $invalid = $this->getWith('/api/v1/products/not-a-uuid', '*');
        $invalid->assertStatus(400);
        $this->assertFalse($invalid->headers->has('ETag'));

        $write = $this->patchJson($this->detailUrl(), ['price' => 11], ['Authorization' => 'Bearer '.$this->adminToken()]);
        $write->assertOk();
        $this->assertFalse($write->headers->has('ETag'), 'no ETag on writes');
    }

    public function test_if_none_match_lists_star_and_weak_tags(): void
    {
        $etag = $this->getWith($this->detailUrl())->headers->get('ETag');

        $this->getWith($this->detailUrl(), '"other", '.$etag)->assertStatus(304);
        $this->getWith($this->detailUrl(), '"a","b" , W/'.$etag)->assertStatus(304);
        $this->getWith($this->detailUrl(), 'W/'.$etag)->assertStatus(304);
        $this->getWith($this->detailUrl(), '*')->assertStatus(304);
        $this->getWith($this->detailUrl(), '"other", W/"also-other"')->assertOk();
        $this->getWith($this->detailUrl(), '')->assertOk();
    }

    public function test_head_gets_the_same_validators(): void
    {
        $etag = $this->getWith($this->detailUrl())->headers->get('ETag');

        $head = $this->call('HEAD', $this->detailUrl());
        $head->assertOk();
        $this->assertSame($etag, $head->headers->get('ETag'));
        $this->call('HEAD', $this->detailUrl(), [], [], [], ['HTTP_IF_NONE_MATCH' => $etag])->assertStatus(304);
    }

    public function test_the_matcher_follows_rfc_9110(): void
    {
        $this->assertTrue(ETagRevalidation::matches('"abc"', '"abc"'));
        $this->assertTrue(ETagRevalidation::matches('W/"abc"', '"abc"'), 'weak comparison');
        $this->assertTrue(ETagRevalidation::matches(' "x" ,  "abc"', '"abc"'));
        $this->assertTrue(ETagRevalidation::matches('*', '"abc"'));
        $this->assertFalse(ETagRevalidation::matches('"abcd"', '"abc"'));
        $this->assertFalse(ETagRevalidation::matches('abc', '"abc"'), 'an unquoted value is not an entity tag');
        $this->assertFalse(ETagRevalidation::matches(null, '"abc"'));
    }
}
