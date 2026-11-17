<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Orders\OrdersModule;
use App\Shared\Messaging\Topology;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * RabbitMQ asks these four questions (HTTP auth backend) every time a browser
 * connects or subscribes. Laravel answers "allow" or "deny" as plain text.
 *
 *   user      the browser sends login = user id, passcode = its JWT
 *   vhost     may this user use this virtual host?
 *   resource  may this user touch this queue / exchange?
 *   topic     may this user listen on this routing key?  <- the real rule
 *
 * Only reachable on the private port (see the Caddyfile): never publish it.
 */
class RealtimeAuthController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function user(Request $request): Response
    {
        $userId = (string) $request->input('username');

        try {
            $payload = JWT::decode((string) $request->input('password'), new Key((string) config('store.jwt.secret'), 'HS256'));
        } catch (Throwable) {
            return $this->deny();
        }

        $role = $payload->role ?? null;
        if (($payload->sub ?? null) !== $userId || ! in_array($role, ['customer', 'admin'], true)) {
            return $this->deny();
        }

        // RabbitMQ sends only the user name in the next questions, so remember
        // the role until the token expires (after that, new subscriptions are
        // refused and the browser must reconnect with a fresh token).
        $ttl = max(60, (int) ($payload->exp ?? 0) - time());
        self::store()->put("rt:role:{$userId}", $role, $ttl);

        return $this->allow(); // no tags: this account cannot open the management UI
    }

    public function vhost(Request $request): Response
    {
        return $this->roleOf($request) !== null && $request->input('vhost') === config('rabbitmq.connection.vhost')
            ? $this->allow()
            : $this->deny();
    }

    public function resource(Request $request): Response
    {
        if ($this->roleOf($request) === null) {
            return $this->deny();
        }

        $kind = $request->input('resource');
        $name = (string) $request->input('name');

        // The temporary queue Web-STOMP creates for each SUBSCRIBE.
        if ($kind === 'queue') {
            return preg_match('/^stomp-subscription-[A-Za-z0-9_-]+$/', $name) === 1 ? $this->allow() : $this->deny();
        }

        // Browsers may only READ from the real-time exchange. They can never
        // publish, and the internal exchange (store.events) is invisible to them.
        if ($kind === 'exchange') {
            return $name === Topology::REALTIME && $request->input('permission') === 'read' ? $this->allow() : $this->deny();
        }

        return $this->deny();
    }

    public function topic(Request $request): Response
    {
        $role = $this->roleOf($request);
        if ($role === null
            || $request->input('name') !== Topology::REALTIME
            || $request->input('permission') !== 'read') {
            return $this->deny();
        }

        $key = (string) $request->input('routing_key');

        if ($key === 'admin.orders') {
            return $role === 'admin' ? $this->allow() : $this->deny();
        }

        // Exactly "order.<uuid>": no wildcards (# or *), so nobody can listen to everything.
        if (preg_match('/^order\.('.self::UUID.')$/', $key, $match) === 1) {
            if ($role === 'admin') {
                return $this->allow();
            }

            return app(OrdersModule::class)->isOwnedBy($match[1], (string) $request->input('username'))
                ? $this->allow()
                : $this->deny();
        }

        return $this->deny();
    }

    private function roleOf(Request $request): ?string
    {
        $role = self::store()->get('rt:role:'.$request->input('username'));

        return is_string($role) ? $role : null;
    }

    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        // Redis in the app, the array store in tests (REALTIME_CACHE_STORE).
        return Cache::store(config('rabbitmq.cache_store'));
    }

    private function allow(): Response
    {
        return new Response('allow', 200, ['Content-Type' => 'text/plain']);
    }

    private function deny(): Response
    {
        return new Response('deny', 200, ['Content-Type' => 'text/plain']);
    }
}