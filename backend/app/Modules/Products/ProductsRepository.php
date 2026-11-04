<?php

namespace App\Modules\Products;

use App\Shared\Time;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the products module that talks to the database.
 * price is NUMERIC and comes back as a string ("19.99"): exact, no float
 * rounding. Stock is not here any more: the inventory service owns it.
 */
class ProductsRepository
{
    private const PRODUCT_COLUMNS = 'id, name, description, category, price, image_url, created_at, updated_at';

    public function create(array $data): array
    {
        $rows = DB::select(
            'INSERT INTO products (name, description, category, price, image_url)
             VALUES (?, ?, ?, ?, ?)
             RETURNING '.self::PRODUCT_COLUMNS,
            [$data['name'], $data['description'], $data['category'], $data['price'], $data['image_url']],
        );

        return self::row($rows[0]);
    }

    public function findById(string $id): ?array
    {
        $rows = DB::select('SELECT '.self::PRODUCT_COLUMNS.' FROM products WHERE id = ?', [$id]);

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * One page of products (newest first) plus the total count. category is optional.
     *
     * @return array{rows: array, total: int}
     */
    public function list(?string $category, int $limit, int $offset): array
    {
        $params = [];
        $whereSql = '';
        if ($category !== null) {
            $params[] = $category;
            $whereSql = 'WHERE category = ?';
        }

        $page = DB::select(
            'SELECT '.self::PRODUCT_COLUMNS." FROM products {$whereSql}
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );
        $count = DB::select("SELECT COUNT(*)::int AS total FROM products {$whereSql}", $params);

        return ['rows' => array_map(self::row(...), $page), 'total' => $count[0]->total];
    }

    /**
     * fields: only keys the service allows (name, description, category, price).
     * Column names never come from the request itself; values are always parameters.
     */
    public function update(string $id, array $fields): ?array
    {
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $id;

        $rows = DB::select(
            'UPDATE products SET '.implode(', ', $sets).', updated_at = NOW()
              WHERE id = ?
              RETURNING '.self::PRODUCT_COLUMNS,
            $params,
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * Name and price for order lines, used through ProductsModule.
     *
     * @param list<string> $ids
     * @return array<string, array{id: string, name: string, price: string}>
     */
    public function findForOrder(array $ids): array
    {
        $rows = DB::select(
            'SELECT id, name, price FROM products WHERE id = ANY(?::uuid[])',
            ['{'.implode(',', $ids).'}'],
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = (array) $row;
        }

        return $byId;
    }

    private static function row(object $row): array
    {
        $product = (array) $row;
        $product['created_at'] = Time::iso($product['created_at']);
        $product['updated_at'] = Time::iso($product['updated_at']);

        return $product;
    }
}
