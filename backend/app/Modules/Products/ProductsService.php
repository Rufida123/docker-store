<?php

namespace App\Modules\Products;

use App\Shared\AppError;
use App\Shared\Text;
use App\Shared\Validation;
use Throwable;

/**
 * Business rules of the products module (validation, images, stock).
 */
class ProductsService
{
    private const MAX_STOCK = 1000000;

    private const PRICE_RE = '/^\d{1,8}(\.\d{1,2})?$/'; // 0 to 99999999.99, at most 2 decimals

    private const INT_RE = '/^-?\d+$/';

    public function __construct(private ProductsRepository $repo)
    {
    }

    // ---- input helpers ----
    // Multipart form fields always arrive as strings, JSON fields as numbers: both are accepted.

    private static function toPrice(mixed $value): ?string
    {
        $text = '';
        if (is_int($value) || is_float($value)) {
            $text = self::numberToString($value);
        } elseif (is_string($value)) {
            $text = Text::trim($value);
        }

        return preg_match(self::PRICE_RE, $text) ? $text : null; // kept as a string, stored as NUMERIC (exact)
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            // Whole floats count as integers (JSON "2.0" means 2).
            return is_finite($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX ? (int) $value : null;
        }
        if (is_string($value) && preg_match(self::INT_RE, Text::trim($value))) {
            return (int) Text::trim($value);
        }

        return null;
    }

    private static function numberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value == 0) {
            return '0';
        }

        return is_finite($value) && floor($value) === $value && abs($value) < 1e15
            ? sprintf('%.0f', $value)
            : (string) $value;
    }

    /**
     * Optional text: missing = not sent (null return with $present=false),
     * null or '' = clear it, otherwise a trimmed string.
     */
    private static function readText(array $body, string $field, int $maxLength, array &$details, ?bool &$present): ?string
    {
        $present = array_key_exists($field, $body);
        if (! $present) {
            return null;
        }

        $value = $body[$field];
        if ($value === null || (is_string($value) && Text::trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || Text::length(Text::trim($value)) > $maxLength) {
            $details[] = ['field' => $field, 'message' => "{$field} must be text of at most {$maxLength} characters"];
            $present = false;

            return null;
        }

        return Text::trim($value);
    }

    private static function checkProductId(mixed $id): void
    {
        if (! Validation::isUuid($id)) {
            throw Validation::error([['field' => 'id', 'message' => 'id must be a valid UUID']]);
        }
    }

    /**
     * Reads name, description, category and price. With partial=true (edits)
     * every field is optional.
     *
     * @return array{data: array, details: array}
     */
    private static function parseDetails(array $body, bool $partial): array
    {
        $details = [];
        $data = [];

        if (array_key_exists('name', $body) || ! $partial) {
            $name = is_string($body['name'] ?? null) ? Text::trim($body['name']) : '';
            if ($name === '' || Text::length($name) > 200) {
                $details[] = ['field' => 'name', 'message' => 'name is required (1 to 200 characters)'];
            } else {
                $data['name'] = $name;
            }
        }

        $description = self::readText($body, 'description', 2000, $details, $descriptionSent);
        if ($descriptionSent) {
            $data['description'] = $description;
        }

        // Categories are stored in lower case so "Electronics" and "electronics" are one category.
        $category = self::readText($body, 'category', 100, $details, $categorySent);
        if ($categorySent) {
            $data['category'] = $category === null ? null : mb_strtolower($category);
        }

        if (array_key_exists('price', $body) || ! $partial) {
            $price = self::toPrice($body['price'] ?? null);
            if ($price === null) {
                $details[] = [
                    'field' => 'price',
                    'message' => 'price must be a number from 0 to 99999999.99 with at most 2 decimals',
                ];
            } else {
                $data['price'] = $price;
            }
        }

        return ['data' => $data, 'details' => $details];
    }

    // ---- use cases ----

    /**
     * POST /products (admin). $imageBytes = the optional uploaded image.
     */
    public function createProduct(array $body, ?string $imageBytes): array
    {
        ['data' => $data, 'details' => $details] = self::parseDetails($body, partial: false);

        $stock = 0;
        if (array_key_exists('stock', $body)) {
            $stock = self::toInt($body['stock']);
            if ($stock === null || $stock < 0 || $stock > self::MAX_STOCK) {
                $details[] = ['field' => 'stock', 'message' => 'stock must be an integer from 0 to '.self::MAX_STOCK];
            }
        }
        if ($details !== []) {
            throw Validation::error($details);
        }

        // The image is checked and saved only after all other fields are valid.
        $imageUrl = $imageBytes !== null ? ProductsImages::save($imageBytes) : null;
        try {
            return $this->repo->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'price' => $data['price'],
                'stock' => $stock,
                'image_url' => $imageUrl,
            ]);
        } catch (Throwable $e) {
            if ($imageUrl !== null) {
                ProductsImages::remove($imageUrl); // no orphan files
            }
            throw $e;
        }
    }

    /**
     * GET /products?category=&page=&limit= (public)
     */
    public function listProducts(array $query): array
    {
        $pagination = Validation::parsePagination($query);

        $category = null;
        if (array_key_exists('category', $query)) {
            $raw = $query['category'];
            if (! is_string($raw) || Text::trim($raw) === '' || Text::length($raw) > 100) {
                throw Validation::error([['field' => 'category', 'message' => 'category must be text of 1 to 100 characters']]);
            }
            $category = mb_strtolower(Text::trim($raw));
        }

        ['rows' => $rows, 'total' => $total] = $this->repo->list($category, $pagination['limit'], $pagination['offset']);

        return Validation::paginated($rows, $pagination, $total);
    }

    /**
     * GET /products/:id (public)
     */
    public function getProduct(mixed $id): array
    {
        self::checkProductId($id);

        $product = $this->repo->findById(mb_strtolower($id));
        if ($product === null) {
            throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
        }

        return $product;
    }

    /**
     * PATCH /products/:id (admin): name, description, category, price.
     * Stock is not edited here, see changeStock.
     */
    public function updateProduct(mixed $id, array $body): array
    {
        self::checkProductId($id);

        ['data' => $data, 'details' => $details] = self::parseDetails($body, partial: true);
        if (array_key_exists('stock', $body)) {
            $details[] = ['field' => 'stock', 'message' => 'use PATCH /products/:id/stock to change stock'];
        }
        if ($details === [] && $data === []) {
            $details[] = ['field' => 'body', 'message' => 'send at least one of: name, description, category, price'];
        }
        if ($details !== []) {
            throw Validation::error($details);
        }

        $product = $this->repo->update(mb_strtolower($id), $data);
        if ($product === null) {
            throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
        }

        return $product;
    }

    /**
     * PATCH /products/:id/stock (admin). Body: { change: 25 } to add, { change: -3 }
     * to remove. Relative and atomic, so it is safe while customers are placing
     * orders at the same time.
     */
    public function changeStock(mixed $id, array $body): array
    {
        self::checkProductId($id);

        $change = self::toInt($body['change'] ?? null);
        if ($change === null || $change === 0 || abs($change) > self::MAX_STOCK) {
            throw Validation::error([
                ['field' => 'change', 'message' => 'change must be a non-zero integer between -'.self::MAX_STOCK.' and '.self::MAX_STOCK],
            ]);
        }

        $product = $this->repo->changeStock(mb_strtolower($id), $change);
        if ($product !== null) {
            return $product;
        }

        $current = $this->repo->findStock(mb_strtolower($id));
        if ($current === null) {
            throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
        }
        throw new AppError(409, 'INSUFFICIENT_STOCK', 'Stock cannot go below zero', [
            ['field' => 'change', 'available' => $current['stock'], 'message' => "Only {$current['stock']} in stock"],
        ]);
    }

    // ---- used by other modules (through ProductsModule) ----

    /**
     * Every caller locks product rows in the same order (sorted by id).
     * Without this, order A (mouse, keyboard) and order B (keyboard, mouse)
     * could each hold one lock while waiting for the other: a deadlock.
     */
    private static function sortById(array $items): array
    {
        usort($items, fn (array $a, array $b) => strcmp($a['product_id'], $b['product_id']));

        return $items;
    }

    /**
     * Reserves stock for an order. Runs inside the caller's transaction, so if
     * anything later fails (or one product is short), all stock taken here is
     * given back by ROLLBACK.
     *
     * items: [{ product_id, quantity }]  ->  [{ product_id, name, price, quantity }]
     */
    public function reserveStock(array $items): array
    {
        $reserved = [];

        foreach (self::sortById($items) as $item) {
            $product = $this->repo->decrementStock($item['product_id'], $item['quantity']);

            if ($product === null) {
                $current = $this->repo->findStock($item['product_id']);
                if ($current === null) {
                    throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found', [
                        ['field' => 'items', 'product_id' => $item['product_id'], 'message' => 'No product with this id'],
                    ]);
                }
                throw new AppError(409, 'INSUFFICIENT_STOCK', 'Not enough stock for this order', [
                    [
                        'field' => 'items',
                        'product_id' => $item['product_id'],
                        'requested' => $item['quantity'],
                        'available' => $current['stock'],
                        'message' => "Only {$current['stock']} left in stock",
                    ],
                ]);
            }

            $reserved[] = [
                'product_id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $item['quantity'],
            ];
        }

        return $reserved;
    }

    /**
     * Gives stock back (for example when an order is cancelled), inside the
     * caller's transaction. items: [{ product_id, quantity }]
     */
    public function releaseStock(array $items): void
    {
        foreach (self::sortById($items) as $item) {
            $this->repo->incrementStock($item['product_id'], $item['quantity']);
        }
    }
}
