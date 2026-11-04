<?php

namespace App\Modules\Products;

use App\Shared\Time;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the products module that talks to the database.
 * price is NUMERIC and comes back as a string ("19.99"): exact, no float rounding.
 */
class ProductsRepository
{
    private const PRODUCT_COLUMNS = 'id, name, description, category, price, stock, image_url, created_at, updated_at';

    public function create(array $data): array
    {
        $rows = DB::select(
            'INSERT INTO products (name, description, category, price, stock, image_url)
             VALUES (?, ?, ?, ?, ?, ?)
             RETURNING '.self::PRODUCT_COLUMNS,
            [$data['name'], $data['description'], $data['category'], $data['price'], $data['stock'], $data['image_url']],
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
     * Adds (or removes, if negative) stock relative to the CURRENT value, in one
     * statement. Never "set stock = X" from a value read earlier: an order placed
     * in between would be lost. Returns null if the product is missing or the
     * change would go below zero.
     */
    public function changeStock(string $id, int $change): ?array
    {
        $rows = DB::select(
            'UPDATE products
                SET stock = stock + ?, updated_at = NOW()
              WHERE id = ? AND stock + ? >= 0
              RETURNING '.self::PRODUCT_COLUMNS,
            [$change, $id, $change],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    // ---- used by other modules through ProductsModule, inside their transaction ----

    /**
     * Takes `quantity` from stock only if that much is left, in ONE statement.
     * UPDATE locks the product row, so concurrent orders for the same product
     * wait for each other, and `stock >= ?` is re-checked on the latest value.
     * Stock can never go negative. Returns id/name/price, or null if the product
     * is missing or short.
     */
    public function decrementStock(string $productId, int $quantity): ?array
    {
        $rows = DB::select(
            'UPDATE products
                SET stock = stock - ?, updated_at = NOW()
              WHERE id = ? AND stock >= ?
              RETURNING id, name, price',
            [$quantity, $productId, $quantity],
        );

        return $rows === [] ? null : (array) $rows[0];
    }

    public function incrementStock(string $productId, int $quantity): void
    {
        DB::update(
            'UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?',
            [$quantity, $productId],
        );
    }

    public function findStock(string $productId): ?array
    {
        $rows = DB::select('SELECT id, stock FROM products WHERE id = ?', [$productId]);

        return $rows === [] ? null : (array) $rows[0];
    }

    private static function row(object $row): array
    {
        $product = (array) $row;
        $product['created_at'] = Time::iso($product['created_at']);
        $product['updated_at'] = Time::iso($product['updated_at']);

        return $product;
    }
}
