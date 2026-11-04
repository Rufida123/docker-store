<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * All JSON leaves through here so the wire format stays identical everywhere:
 * slashes and unicode unescaped, charset spelled out, and one error envelope.
 */
final class ApiResponse
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        $headers += ['Content-Type' => 'application/json; charset=utf-8'];

        return new JsonResponse($data, $status, $headers, self::JSON_FLAGS);
    }

    public static function error(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status);
    }
}
