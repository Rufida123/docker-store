<?php

namespace App\Shared;

/**
 * Per-request counters for load tests (docs/performance.md). Modules count
 * here; the QueryCounter middleware reports them as response headers when
 * PERF_QUERY_LOG=true. Plain static state: each PHP request starts with zeros
 * in classic mode, and the middleware resets them for long-lived workers.
 */
final class PerfCounters
{
    /** @var array<string, int> */
    private static array $counts = [];

    public static function add(string $name, int $by = 1): void
    {
        self::$counts[$name] = (self::$counts[$name] ?? 0) + $by;
    }

    public static function get(string $name): int
    {
        return self::$counts[$name] ?? 0;
    }

    public static function reset(): void
    {
        self::$counts = [];
    }
}
