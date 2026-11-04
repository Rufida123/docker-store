<?php

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Timestamp formatting for API responses: UTC, millisecond precision,
 * trailing Z (e.g. 2026-11-03T10:15:30.123Z).
 */
final class Time
{
    public static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (new DateTimeImmutable((string) $value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
