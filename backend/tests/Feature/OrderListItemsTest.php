<?php

namespace Tests\Feature;

use App\Modules\Orders\OrdersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /orders returns each order with its items, fetched for the whole page
 * in one query: the number of queries must not grow with the page size (no N+1).
 */
class OrderListItemsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrders(string $userId, int $count, int $itemsPerOrder): void
    {
        for ($i = 0; $i < $count; $i++) {
            $orderId = (string) Str::uuid();
            DB::insert(
                "INSERT INTO orders (id, user_id, total, created_at) VALUES (?, ?, 0, NOW() - ? * interval '1 minute')",
                [$orderId, $userId, $i],
            );
            for ($j = 0; $j < $itemsPerOrder; $j++) {
                DB::insert(
                    'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, 2.50, 2)',
                    [$orderId, (string) Str::uuid(), "Product {$j}"],
                );
            }
        }
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_each_order_in_the_list_carries_its_items(): void
    {
        $user = ['id' => (string) Str::uuid(), 'role' => 'customer'];
        $this->makeOrders($user['id'], 3, 2);
        $this->makeOrders((string) Str::uuid(), 2, 4); // someone else's

        $page = app(OrdersService::class)->listOrders($user, []);

        $this->assertCount(3, $page['data']);
        foreach ($page['data'] as $order) {
            $this->assertCount(2, $order['items']);
            $this->assertSame(['product_id', 'product_name', 'unit_price', 'quantity', 'line_total'], array_keys($order['items'][0]));
            $this->assertSame('5.00', $order['items'][0]['line_total']);
        }
    }

    public function test_the_query_count_does_not_grow_with_the_page_size(): void
    {
        $small = ['id' => (string) Str::uuid(), 'role' => 'customer'];
        $large = ['id' => (string) Str::uuid(), 'role' => 'customer'];
        $this->makeOrders($small['id'], 2, 1);
        $this->makeOrders($large['id'], 20, 5);

        $service = app(OrdersService::class);
        $smallQueries = $this->countQueries(fn () => $service->listOrders($small, []));
        $largeQueries = $this->countQueries(fn () => $service->listOrders($large, []));

        $this->assertSame(3, $smallQueries, 'page + count + items');
        $this->assertSame($smallQueries, $largeQueries, 'the same queries for 2 or 20 orders');
    }

    public function test_an_empty_page_runs_no_items_query(): void
    {
        $user = ['id' => (string) Str::uuid(), 'role' => 'customer'];

        $queries = $this->countQueries(fn () => app(OrdersService::class)->listOrders($user, []));

        $this->assertSame(2, $queries);
    }
}
