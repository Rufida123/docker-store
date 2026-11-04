<?php

namespace App\Shared;

/**
 * String semantics the validation rules were originally written against:
 * lengths in UTF-16 code units, unicode-aware trim, and Number()-style
 * string-to-number conversion.
 */
final class Text
{
    public static function length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function trim(string $value): string
    {
        return preg_replace('/(*UCP)^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? trim($value);
    }

    /**
     * Number(value) for a string: empty (after trim) is 0, decimal and
     * exponent notation parse, anything else is NAN.
     */
    public static function toNumber(string $value): float
    {
        $trimmed = self::trim($value);

        if ($trimmed === '') {
            return 0.0;
        }

        if (! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $trimmed)) {
            return NAN;
        }

        return (float) $trimmed;
    }
}
