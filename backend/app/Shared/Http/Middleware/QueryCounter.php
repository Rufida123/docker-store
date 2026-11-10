<?php

namespace App\Shared\Http\Middleware;

use App\Shared\PerfCounters;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Performance measurement only: adds X-Query-Count and X-Query-Time-Ms (the
 * database time PostgreSQL reported for this request) to every response.
 *
 * Added to the global middleware stack by AppServiceProvider only when
 * PERF_QUERY_LOG=true (config store.perf.query_log). When the flag is off it
 * is not in the stack at all: no cost, no headers.
 */
class QueryCounter
{
    public function handle(Request $request, Closure $next): Response
    {
        $count = 0;
        $timeMs = 0.0;
        $counting = true;
        PerfCounters::reset();

        DB::listen(function (QueryExecuted $query) use (&$count, &$timeMs, &$counting): void {
            if ($counting) {
                $count++;
                $timeMs += $query->time;
            }
        });

        try {
            $response = $next($request);
        } finally {
            // The listener stays registered for the rest of this app instance; stop it counting.
            $counting = false;
        }

        $response->headers->set('X-Query-Count', (string) $count);
        $response->headers->set('X-Query-Time-Ms', number_format($timeMs, 2, '.', ''));
        // Catalog cache lookups answered from the cache / computed (ProductsCache).
        $response->headers->set('X-Cache-Hits', (string) PerfCounters::get('cache_hit'));
        $response->headers->set('X-Cache-Misses', (string) PerfCounters::get('cache_miss'));
        // Read routing (ReadRouter): where this request's reads went, what the
        // decision cost, and where cache refills read from.
        // "primary" also covers reads Laravel keeps on the primary by itself
        // (writes, transactions, sticky) and refills forced there; "none" means
        // no query at all (answered from the cache).
        $response->headers->set('X-DB-Read', match (true) {
            PerfCounters::get('db_read_replica') > 0 => 'replica',
            $count > 0 => 'primary',
            default => 'none',
        });
        $response->headers->set('X-Route-Us', (string) PerfCounters::get('route_us'));
        $response->headers->set('X-Replica-Connect-Us', (string) PerfCounters::get('replica_connect_us'));
        $response->headers->set('X-Refill-Replica', (string) PerfCounters::get('refill_replica'));
        $response->headers->set('X-Refill-Primary', (string) PerfCounters::get('refill_primary'));

        return $response;
    }
}
