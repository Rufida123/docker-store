<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Notifications\NotificationsModule;
use App\Modules\Orders\OrdersModule;
use App\Modules\Orders\OrdersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/**
 * Inventory double: every reservation succeeds.
 */
class MailTestInventory extends InventoryModule
{
    public function reserve(string $reservationId, array $items): array
    {
        return ['status' => 'reserved', 'shortage' => null];
    }

    public function release(string $reservationId): string
    {
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

/**
 * The order emails sent by the mail worker (NotificationsModule::sendOrderEmail),
 * checked through the array mailer's transport.
 */
class NotificationsMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The handler relies on the global sender; give the test one so the
        // array transport can build the message.
        config(['mail.from' => ['address' => 'store@example.com', 'name' => 'Docker Store']]);
        config(['store.events.publish' => false]);

        Cache::store(config('rabbitmq.cache_store'))->flush();
        $this->app->instance(InventoryModule::class, new MailTestInventory);
    }

    private function makeUser(): array
    {
        $id = (string) Str::uuid();
        $email = 'customer-'.$id.'@example.com';
        DB::insert("INSERT INTO users (id, email, password_hash, role) VALUES (?, ?, 'x', 'customer')", [$id, $email]);

        return [$id, $email];
    }

    private function makeProduct(string $name): string
    {
        $id = (string) Str::uuid();
        DB::insert('INSERT INTO products (id, name, price) VALUES (?, ?, 10.00)', [$id, $name]);

        return $id;
    }

    /** @return array{string, string, string} [orderId, userId, customer email] */
    private function makeOrder(): array
    {
        [$userId, $email] = $this->makeUser();
        $order = app(OrdersService::class)->createOrder(
            ['id' => $userId, 'role' => 'customer'],
            ['items' => [['product_id' => $this->makeProduct('Mail Lamp'), 'quantity' => 2]]],
        );

        return [$order['id'], $userId, $email];
    }

    private function message(string $type, array $data): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => gmdate('c'),
            'data' => $data,
        ];
    }

    /** @return list<Email> */
    private function sentEmails(): array
    {
        return Mail::getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    public function test_an_order_created_message_sends_one_email_to_the_customer(): void
    {
        [$orderId, $userId, $email] = $this->makeOrder();
        $summary = app(OrdersModule::class)->summary($orderId);

        app(NotificationsModule::class)->sendOrderEmail(
            $this->message('order.created', ['orderId' => $orderId, 'userId' => $userId, 'total' => $summary['total']]),
        );

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $this->assertSame($email, $sent[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('#'.substr($orderId, 0, 8), $sent[0]->getSubject());

        $body = (string) $sent[0]->getTextBody();
        $item = $summary['items'][0];
        $this->assertStringContainsString('Mail Lamp', $body);
        $this->assertStringContainsString("- {$item['quantity']} x {$item['product_name']} ({$item['unit_price']})", $body);
        $this->assertStringContainsString('Total: '.$summary['total'], $body);
    }

    public function test_the_same_message_id_a_second_time_sends_nothing_more(): void
    {
        [$orderId, $userId] = $this->makeOrder();
        $message = $this->message('order.created', ['orderId' => $orderId, 'userId' => $userId, 'total' => '20.00']);

        app(NotificationsModule::class)->sendOrderEmail($message);
        app(NotificationsModule::class)->sendOrderEmail($message); // redelivery after a worker crash

        $this->assertCount(1, $this->sentEmails());
    }

    public function test_a_status_changed_message_mentions_the_new_status(): void
    {
        [$orderId, $userId] = $this->makeOrder();

        app(NotificationsModule::class)->sendOrderEmail($this->message('order.status_changed', [
            'orderId' => $orderId,
            'userId' => $userId,
            'from' => 'pending',
            'to' => 'shipped',
            'changedBy' => $userId,
        ]));

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('shipped', $sent[0]->getSubject());
        $this->assertStringContainsString('shipped', (string) $sent[0]->getTextBody());
        $this->assertStringContainsString('#'.substr($orderId, 0, 8), $sent[0]->getSubject());
    }

    public function test_a_message_for_an_order_that_does_not_exist_throws(): void
    {
        [$userId] = $this->makeUser();
        $message = $this->message('order.created', [
            'orderId' => (string) Str::uuid(),
            'userId' => $userId,
            'total' => '0.00',
        ]);

        try {
            app(NotificationsModule::class)->sendOrderEmail($message);
            $this->fail('a missing order must make the handler throw, so the consumer retries');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }

        $this->assertCount(0, $this->sentEmails());
    }
}