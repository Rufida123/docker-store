<?php

namespace Tests\Feature;

use App\Modules\Inventory\InventoryModule;
use App\Modules\Orders\OrdersService;
use App\Shared\Messaging\Topology;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inventory double: every reservation succeeds, so orders can be created
 * without the gRPC inventory service.
 */
class RealtimeAuthInventory extends InventoryModule
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
 * The four questions RabbitMQ asks Laravel (HTTP auth backend): who may
 * connect, use the vhost, touch a queue/exchange, and listen on a routing key.
 * Plain HTTP posts, no broker needed.
 */
class RealtimeAuthTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH = '/api/v1/internal/rabbitmq/auth/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['rabbitmq.connection.vhost' => '/']);
        config(['store.events.publish' => false]);
        Cache::store(config('rabbitmq.cache_store'))->flush();
        $this->app->instance(InventoryModule::class, new RealtimeAuthInventory);
    }

    private function makeUser(string $role = 'customer'): string
    {
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO users (id, email, password_hash, role) VALUES (?, ?, 'x', ?)", [$id, $id.'@example.com', $role]);

        return $id;
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::insert("INSERT INTO products (id, name, price) VALUES (?, 'Test Product', 10.00)", [$id]);

        return $id;
    }

    private function makeOrderFor(string $userId): string
    {
        $order = app(OrdersService::class)->createOrder(
            ['id' => $userId, 'role' => 'customer'],
            ['items' => [['product_id' => $this->makeProduct(), 'quantity' => 1]]],
        );

        return $order['id'];
    }

    private function token(string $userId, string $role = 'customer', ?int $exp = null, ?string $secret = null): string
    {
        return JWT::encode([
            'sub' => $userId,
            'role' => $role,
            'exp' => $exp ?? time() + 3600,
        ], $secret ?? (string) config('store.jwt.secret'), 'HS256');
    }

    /** Posts a form-encoded body and returns the plain-text answer. */
    private function ask(string $endpoint, array $fields): string
    {
        $response = $this->post(self::AUTH.$endpoint, $fields);
        $response->assertOk();

        return (string) $response->getContent();
    }

    /** What the browser's STOMP CONNECT triggers: login = user id, passcode = token. */
    private function login(string $userId, string $role): void
    {
        $this->assertSame('allow', $this->ask('user', [
            'username' => $userId,
            'password' => $this->token($userId, $role),
        ]));
    }

    private function topic(string $userId, string $key, string $exchange = Topology::REALTIME, string $permission = 'read'): string
    {
        return $this->ask('topic', [
            'username' => $userId,
            'vhost' => '/',
            'resource' => 'topic',
            'name' => $exchange,
            'permission' => $permission,
            'routing_key' => $key,
        ]);
    }

    private function resource(string $userId, string $kind, string $name, string $permission): string
    {
        return $this->ask('resource', [
            'username' => $userId,
            'vhost' => '/',
            'resource' => $kind,
            'name' => $name,
            'permission' => $permission,
        ]);
    }

    // ---- user ----

    public function test_a_valid_customer_token_is_allowed(): void
    {
        $userId = $this->makeUser();

        $this->assertSame('allow', $this->ask('user', [
            'username' => $userId,
            'password' => $this->token($userId),
        ]));
    }

    public function test_a_token_whose_sub_differs_from_the_username_is_denied(): void
    {
        $userId = $this->makeUser();
        $otherId = $this->makeUser();

        $this->assertSame('deny', $this->ask('user', [
            'username' => $userId,
            'password' => $this->token($otherId),
        ]));
    }

    public function test_a_garbage_token_is_denied(): void
    {
        $userId = $this->makeUser();

        $this->assertSame('deny', $this->ask('user', [
            'username' => $userId,
            'password' => 'this-is-not-a-jwt',
        ]));
    }

    public function test_an_expired_token_is_denied(): void
    {
        $userId = $this->makeUser();

        $this->assertSame('deny', $this->ask('user', [
            'username' => $userId,
            'password' => $this->token($userId, 'customer', time() - 120),
        ]));

        // A refused login must not leave a role behind.
        $this->assertSame('deny', $this->topic($userId, 'admin.orders'));
    }

    public function test_a_token_signed_with_another_secret_is_denied(): void
    {
        $userId = $this->makeUser();

        $this->assertSame('deny', $this->ask('user', [
            'username' => $userId,
            'password' => $this->token($userId, 'customer', null, 'another-secret-another-secret-0123'),
        ]));
    }

    // ---- topic: customer ----

    public function test_a_customer_may_follow_their_own_order(): void
    {
        $userId = $this->makeUser();
        $orderId = $this->makeOrderFor($userId);
        $this->login($userId, 'customer');

        $this->assertSame('allow', $this->topic($userId, 'order.'.$orderId));
    }

    public function test_a_customer_who_just_created_an_order_is_allowed_at_once(): void
    {
        // The order was committed a moment ago: the ownership check must see it
        // (it reads the primary, not a replica that may still be behind).
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $orderId = $this->makeOrderFor($userId);

        $this->assertSame('allow', $this->topic($userId, 'order.'.$orderId));
    }

    public function test_a_customer_may_not_follow_someone_elses_order(): void
    {
        $userId = $this->makeUser();
        $otherId = $this->makeUser();
        $othersOrder = $this->makeOrderFor($otherId);
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->topic($userId, 'order.'.$othersOrder));
    }

    public function test_a_customer_may_not_listen_on_the_admin_key(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->topic($userId, 'admin.orders'));
    }

    public function test_a_customer_may_not_use_wildcards(): void
    {
        $userId = $this->makeUser();
        $this->makeOrderFor($userId);
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->topic($userId, '#'));
        $this->assertSame('deny', $this->topic($userId, 'order.*'));
    }

    public function test_a_customer_may_not_write_to_the_realtime_exchange(): void
    {
        $userId = $this->makeUser();
        $orderId = $this->makeOrderFor($userId);
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->topic($userId, 'order.'.$orderId, Topology::REALTIME, 'write'));
    }

    public function test_a_customer_may_not_read_the_internal_exchange(): void
    {
        $userId = $this->makeUser();
        $orderId = $this->makeOrderFor($userId);
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->topic($userId, 'order.'.$orderId, Topology::EVENTS, 'read'));
    }

    // ---- topic: admin ----

    public function test_an_admin_may_listen_on_the_admin_key(): void
    {
        $adminId = $this->makeUser('admin');
        $this->login($adminId, 'admin');

        $this->assertSame('allow', $this->topic($adminId, 'admin.orders'));
    }

    public function test_an_admin_may_follow_any_order(): void
    {
        $adminId = $this->makeUser('admin');
        $customerOrder = $this->makeOrderFor($this->makeUser());
        $this->login($adminId, 'admin');

        $this->assertSame('allow', $this->topic($adminId, 'order.'.$customerOrder));
    }

    public function test_an_admin_may_not_use_wildcards_either(): void
    {
        $adminId = $this->makeUser('admin');
        $this->login($adminId, 'admin');

        $this->assertSame('deny', $this->topic($adminId, '#'));
    }

    public function test_a_user_that_never_logged_in_is_denied_everywhere(): void
    {
        $userId = $this->makeUser();
        $orderId = $this->makeOrderFor($userId);

        $this->assertSame('deny', $this->topic($userId, 'order.'.$orderId));
        $this->assertSame('deny', $this->topic($userId, 'admin.orders'));
        $this->assertSame('deny', $this->resource($userId, 'queue', 'stomp-subscription-abc_123-X', 'configure'));
        $this->assertSame('deny', $this->ask('vhost', ['username' => $userId, 'vhost' => '/']));
    }

    // ---- resource ----

    public function test_the_temporary_stomp_queue_is_allowed(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        foreach (['configure', 'read', 'write'] as $permission) {
            $this->assertSame(
                'allow',
                $this->resource($userId, 'queue', 'stomp-subscription-abc_123-X', $permission),
                "queue permission {$permission}",
            );
        }
    }

    public function test_a_real_work_queue_is_denied(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->resource($userId, 'queue', Topology::MAIL_QUEUE, 'read'));
    }

    public function test_the_realtime_exchange_may_only_be_read(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $this->assertSame('allow', $this->resource($userId, 'exchange', Topology::REALTIME, 'read'));
        $this->assertSame('deny', $this->resource($userId, 'exchange', Topology::REALTIME, 'write'));
        $this->assertSame('deny', $this->resource($userId, 'exchange', Topology::EVENTS, 'write'));
    }

    // ---- vhost ----

    public function test_the_configured_vhost_is_allowed(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $this->assertSame('allow', $this->ask('vhost', ['username' => $userId, 'vhost' => '/']));
    }

    public function test_any_other_vhost_is_denied(): void
    {
        $userId = $this->makeUser();
        $this->login($userId, 'customer');

        $this->assertSame('deny', $this->ask('vhost', ['username' => $userId, 'vhost' => 'other']));
        $this->assertSame('deny', $this->ask('vhost', ['username' => $userId, 'vhost' => '/admin']));
    }
}