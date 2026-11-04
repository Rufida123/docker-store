<?php

namespace App\Shared;

/**
 * Small input checks used by more than one module.
 */
final class Validation
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // Ids are checked before any query, so a bad id is a clear 400 and never a database error.
    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID_RE, $value) === 1;
    }

    public static function error(array $details): AppError
    {
        return new AppError(400, 'VALIDATION_ERROR', 'Invalid input', $details);
    }

    /**
     * Reads ?page=&limit= from the query string. Defaults: page 1, limit 20,
     * at most 100 per page.
     *
     * @return array{page: int, limit: int, offset: int}
     */
    public static function parsePagination(array $query, int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page = array_key_exists('page', $query) ? self::number($query['page']) : 1.0;
        $limit = array_key_exists('limit', $query) ? self::number($query['limit']) : (float) $defaultLimit;

        $details = [];
        if (! self::isIntegral($page) || $page < 1) {
            $details[] = ['field' => 'page', 'message' => 'page must be a positive integer'];
        }
        if (! self::isIntegral($limit) || $limit < 1 || $limit > $maxLimit) {
            $details[] = ['field' => 'limit', 'message' => "limit must be an integer between 1 and {$maxLimit}"];
        }
        if ($details !== []) {
            throw self::error($details);
        }

        $page = (int) $page;
        $limit = (int) $limit;

        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    /**
     * Shape of every paginated list response.
     */
    public static function paginated(array $data, array $pagination, int $total): array
    {
        return [
            'data' => $data,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'total_pages' => (int) ceil($total / $pagination['limit']),
            ],
        ];
    }

    private static function number(mixed $value): float
    {
        return is_string($value) ? Text::toNumber($value) : NAN;
    }

    private static function isIntegral(float $value): bool
    {
        return ! is_nan($value) && is_finite($value) && floor($value) === $value;
    }
}
