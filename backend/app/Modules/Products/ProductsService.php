<?php

namespace App\Modules\Products;

use App\Modules\Inventory\InventoryModule;
use App\Shared\AppError;
use App\Shared\InventoryError;
use App\Shared\InventoryTimeout;
use App\Shared\InventoryUnavailable;
use App\Shared\Text;
use App\Shared\Validation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Business rules of the products module (validation, images, stock).
 * Stock itself lives in the inventory service; this module decorates product
 * rows with it and forwards stock changes, through InventoryModule only.
 */
class ProductsService
{
    private const MAX_STOCK = 1000000;

    private const PRICE_RE = '/^\d{1,8}(\.\d{1,2})?$/'; // 0 to 99999999.99, at most 2 decimals

    private const INT_RE = '/^-?\d+$/';

    public function __construct(
        private ProductsRepository $repo,
        private InventoryModule $inventory,
    ) {
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

    /**
     * Products carry their stock after the price, plus where it came from:
     * "available" when the inventory answered, "unavailable" (stock null)
     * when it could not be reached. The catalog itself never fails because of
     * the inventory service.
     */
    private static function withStock(array $product, ?int $stock, string $stockStatus): array
    {
        $decorated = [];
        foreach ($product as $key => $value) {
            $decorated[$key] = $value;
            if ($key === 'price') {
                $decorated['stock'] = $stock;
                $decorated['stock_status'] = $stockStatus;
            }
        }

        return $decorated;
    }

    /**
     * One batched GetStock for a whole response.
     *
     * @return array{levels: array<string, int>, status: 'available'|'unavailable'}
     */
    private function stockLevels(array $productIds): array
    {
        if ($productIds === []) {
            return ['levels' => [], 'status' => 'available'];
        }

        try {
            return ['levels' => $this->inventory->getStock($productIds), 'status' => 'available'];
        } catch (InventoryUnavailable|InventoryTimeout|InventoryError) {
            return ['levels' => [], 'status' => 'unavailable'];
        }
    }

    private static function inventoryFailure(Throwable $e): Throwable
    {
        if ($e instanceof InventoryUnavailable) {
            return new AppError(503, 'INVENTORY_UNAVAILABLE', 'The inventory service is unavailable right now. Please try again in a moment.');
        }
        if ($e instanceof InventoryTimeout) {
            return new AppError(504, 'INVENTORY_TIMEOUT', 'The inventory service did not respond in time. Please try again.');
        }

        return $e;
    }

    // ---- use cases ----

    /**
     * POST /products (admin). $imageBytes = the optional uploaded image.
     * The product row and its initial stock go together: when the inventory
     * call fails, the database transaction rolls back and the image is removed.
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
            return DB::transaction(function () use ($data, $stock, $imageUrl): array {
                $product = $this->repo->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? null,
                    'price' => $data['price'],
                    'image_url' => $imageUrl,
                ]);

                $available = 0;
                if ($stock > 0) {
                    $available = $this->inventory->adjust($product['id'], $stock)['available'];
                }

                return self::withStock($product, $available, 'available');
            });
        } catch (Throwable $e) {
            if ($imageUrl !== null) {
                ProductsImages::remove($imageUrl); // no orphan files
            }
            throw self::inventoryFailure($e);
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

        $stock = $this->stockLevels(array_column($rows, 'id'));
        $rows = array_map(
            fn (array $product) => self::withStock(
                $product,
                $stock['status'] === 'available' ? ($stock['levels'][$product['id']] ?? 0) : null,
                $stock['status'],
            ),
            $rows,
        );

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

        $stock = $this->stockLevels([$product['id']]);

        return self::withStock(
            $product,
            $stock['status'] === 'available' ? ($stock['levels'][$product['id']] ?? 0) : null,
            $stock['status'],
        );
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

        $stock = $this->stockLevels([$product['id']]);

        return self::withStock(
            $product,
            $stock['status'] === 'available' ? ($stock['levels'][$product['id']] ?? 0) : null,
            $stock['status'],
        );
    }

    /**
     * PATCH /products/:id/stock (admin). Body: { change: 25 } to add,
     * { change: -3 } to remove. The change is relative and applied by the
     * inventory service, so it is safe while customers are ordering.
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

        $product = $this->repo->findById(mb_strtolower($id));
        if ($product === null) {
            throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
        }

        try {
            $result = $this->inventory->adjust($product['id'], $change);
        } catch (Throwable $e) {
            throw self::inventoryFailure($e);
        }

        if ($result['status'] === 'refused') {
            throw new AppError(409, 'INSUFFICIENT_STOCK', 'Stock cannot go below zero', [
                ['field' => 'change', 'available' => $result['available'], 'message' => "Only {$result['available']} in stock"],
            ]);
        }

        return self::withStock($product, $result['available'], 'available');
    }

    /**
     * Name and price for an order's lines, in sorted product_id order (used
     * by the orders module through ProductsModule). Unknown products get the
     * order flow's 404.
     *
     * items: [{ product_id, quantity }] -> [{ product_id, name, price, quantity }]
     */
    public function getOrderLines(array $items): array
    {
        $sorted = $items;
        usort($sorted, fn (array $a, array $b) => strcmp($a['product_id'], $b['product_id']));

        $byId = $this->repo->findForOrder(array_column($sorted, 'product_id'));

        $lines = [];
        foreach ($sorted as $item) {
            $product = $byId[$item['product_id']] ?? null;
            if ($product === null) {
                throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found', [
                    ['field' => 'items', 'product_id' => $item['product_id'], 'message' => 'No product with this id'],
                ]);
            }
            $lines[] = [
                'product_id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $item['quantity'],
            ];
        }

        return $lines;
    }
}
