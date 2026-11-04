<?php

namespace App\Shared;

use RuntimeException;

/**
 * An expected, user-facing failure. The exception renderer turns it into the
 * unified { "error": { "code", "message", "details"? } } envelope; details is
 * only included when it was given.
 */
class AppError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
