<?php

namespace Tests\Feature;

use App\Shared\Database\ReadRouter;
use App\Shared\Http\Middleware\RecordWrites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A router with a replica configured, whose primary is at a fixed position.
 */
class RecordingRouter extends ReadRouter
{
    public function enabled(): bool
    {
        return true;
    }

    public function primaryLsn(): string
    {
        return '0/ABC0';
    }
}

/**
 * RecordWrites (docs/performance.md, 6.6): a successful write by a signed-in
 * user stores their write position BEFORE the response is handed back.
 */
class RecordWritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['store.replica.store' => 'array']);
        Cache::store('array')->flush();
    }

    private function sendThrough(string $method, int $status, ?string $userId): ?string
    {
        $request = Request::create('/api/v1/orders', $method);
        if ($userId !== null) {
            $request->attributes->set('user', ['id' => $userId, 'role' => 'customer']);
        }

        $keyDuringNext = 'not checked';
        (new RecordWrites(new RecordingRouter))->handle($request, function () use (&$keyDuringNext, $userId, $status) {
            $keyDuringNext = Cache::store('array')->get('ryw:user:'.$userId);

            return new Response('', $status);
        });

        // handle() has returned: this is the moment the response leaves.
        $this->assertNull($keyDuringNext, 'nothing recorded before the write ran');

        return Cache::store('array')->get('ryw:user:'.$userId);
    }

    public function test_the_write_position_exists_when_the_response_leaves(): void
    {
        $this->assertSame('0/ABC0', $this->sendThrough('POST', 201, 'user-1'));
        $this->assertSame('0/ABC0', $this->sendThrough('PATCH', 200, 'user-2'));
        $this->assertSame('0/ABC0', $this->sendThrough('DELETE', 204, 'user-3'));
    }

    public function test_it_is_never_deferred_to_terminate(): void
    {
        // terminate() runs after the response is sent; a fast client could
        // already be reading from the replica by then.
        $this->assertFalse(method_exists(RecordWrites::class, 'terminate'));
    }

    public function test_reads_failures_and_anonymous_requests_record_nothing(): void
    {
        $this->assertNull($this->sendThrough('GET', 200, 'user-4'), 'a read');
        $this->assertNull($this->sendThrough('POST', 409, 'user-5'), 'a refused write');
        $this->assertNull($this->sendThrough('POST', 500, 'user-6'), 'a failed write');
        $this->assertSame([], array_filter([Cache::store('array')->get('ryw:user:')]), 'no user, no key');
    }
}
