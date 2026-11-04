<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Orders\OrdersService;
use App\Shared\InventoryUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Test double for the inventory gateway: reserves always succeed, and every
 * call is recorded. release() can be told to fail, to exercise the outbox.
 */
class RecordingInventory extends InventoryModule
{
    public array $reserved = [];

    public array $released = [];

    public bool $releaseFails = false;

    public function reserve(string $reservationId, array $items): array
    {
        $this->reserved[] = $reservationId;

        return ['status' => 'reserved', 'shortage' => null];
    }

    public function release(string $reservationId): string
    {
        if ($this->releaseFails) {
            throw new InventoryUnavailable('inventory is down (test)');
        }
        $this->released[] = $reservationId;

        return 'released';
    }

    public function getStock(array $productIds): array
    {
        return array_fill_keys($productIds, 100);
    }

    public function adjust(string $productId, int $change): array
    {
        return ['status' => 'applied', 'available' => max(0, $change)];
    }
}

class OrderCompensationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new RecordingInventory;
        $this->app->instance(InventoryModule::class, $this->inventory);
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO products (id, name, price) VALUES (?, 'Test Product', 10.00)", [$id]);

        return $id;
    }

    private function makeUser(): string
    {
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO users (id, email, password_hash, role) VALUES (?, ?, 'x', 'customer')", [$id, $id.'@example.com']);

        return $id;
    }

    public function test_a_failed_order_save_releases_the_reservation(): void
    {
        $productId = $this->makeProduct();
        // A non-UUID user id makes the order INSERT itself fail, after the
        // reserve succeeded: the compensation path in one step.
        $user = ['id' => 'not-a-valid-uuid', 'role' => 'customer'];

        try {
            app(OrdersService::class)->createOrder($user, [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ]);
            $this->fail('the order save should have failed');
        } catch (Throwable) {
            // expected: the save blew up after the reserve
        }

        $this->assertCount(1, $this->inventory->reserved);
        $this->assertSame($this->inventory->reserved, $this->inventory->released, 'the reservation must be released');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('inventory_outbox')->count(), 'a delivered release needs no outbox row');
    }

    public function test_a_failed_release_is_parked_in_the_outbox_and_flushed_later(): void
    {
        $productId = $this->makeProduct();
        $user = ['id' => 'not-a-valid-uuid', 'role' => 'customer'];
        $this->inventory->releaseFails = true;

        try {
            app(OrdersService::class)->createOrder($user, [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ]);
            $this->fail('the order save should have failed');
        } catch (Throwable) {
            // expected
        }

        $reservationId = $this->inventory->reserved[0];
        $this->assertSame([], $this->inventory->released);
        $this->assertSame(1, DB::table('inventory_outbox')->where('reservation_id', $reservationId)->count());

        // The service comes back; the scheduled command delivers the release.
        $this->inventory->releaseFails = false;
        $this->artisan('inventory:flush-outbox')
            ->expectsOutputToContain('1 released, 0 still pending')
            ->assertExitCode(0);

        $this->assertSame([$reservationId], $this->inventory->released);
        $this->assertSame(0, DB::table('inventory_outbox')->count());
    }

    public function test_a_cancelled_order_parks_and_delivers_its_release(): void
    {
        $productId = $this->makeProduct();
        $userId = $this->makeUser();
        $adminId = $this->makeUser();

        $order = app(OrdersService::class)->createOrder(
            ['id' => $userId, 'role' => 'customer'],
            ['items' => [['product_id' => $productId, 'quantity' => 2]]],
        );

        app(OrdersService::class)->changeStatus(
            ['id' => $adminId, 'role' => 'admin'],
            $order['id'],
            ['status' => 'cancelled'],
        );

        $this->assertContains($order['id'], $this->inventory->released);
        $this->assertSame(0, DB::table('inventory_outbox')->count());
    }
}
