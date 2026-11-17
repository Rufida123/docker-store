<?php

namespace Tests\Feature;

use App\Shared\Http\Middleware\QueryCounter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PERF_QUERY_LOG: off (the default) means no middleware and no headers; on
 * means every response reports its query count and database time.
 */
class QueryCounterTest extends TestCase
{
    protected function tearDown(): void
    {
        self::setFlag(null);
        parent::tearDown();
    }

    private static function setFlag(?string $value): void
    {
        if ($value === null) {
            putenv('PERF_QUERY_LOG');
            unset($_ENV['PERF_QUERY_LOG'], $_SERVER['PERF_QUERY_LOG']);
        } else {
            putenv("PERF_QUERY_LOG={$value}");
            $_ENV['PERF_QUERY_LOG'] = $_SERVER['PERF_QUERY_LOG'] = $value;
        }
    }

    private function addProbeRoute(): void
    {
        Route::get('/__perf-probe', function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');

            return response()->json(['ok' => true]);
        });
    }

    public function test_off_by_default_no_middleware_and_no_headers(): void
    {
        $this->assertFalse(config('store.perf.query_log'));
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(QueryCounter::class));

        $this->addProbeRoute();
        $response = $this->get('/__perf-probe');

        $response->assertOk();
        $response->assertHeaderMissing('X-Query-Count');
        $response->assertHeaderMissing('X-Query-Time-Ms');
    }

    public function test_on_reports_query_count_and_time(): void
    {
        self::setFlag('true');
        $this->refreshApplication();

        $this->assertTrue(config('store.perf.query_log'));
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(QueryCounter::class));

        $this->addProbeRoute();
        $response = $this->get('/__perf-probe');

        $response->assertOk();
        $response->assertHeader('X-Query-Count', '2');
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $response->headers->get('X-Query-Time-Ms'));
    }

    public function test_on_also_counts_on_error_responses(): void
    {
        self::setFlag('true');
        $this->refreshApplication();

        $response = $this->get('/api/v1/no-such-route');

        $response->assertNotFound();
        $response->assertHeader('X-Query-Count', '0');
    }
}
