<?php

namespace App\Modules\Products;

/**
 * Public face of the products module.
 * Other code may only use this class, never the classes next to it.
 *
 * reserveStock / releaseStock run inside the caller's transaction, so stock
 * changes commit or roll back together with the caller's own changes (used by
 * orders).
 */
class ProductsModule
{
    public function __construct(private ProductsService $service)
    {
    }

    /**
     * items: [{ product_id, quantity }]  ->  [{ product_id, name, price, quantity }]
     */
    public function reserveStock(array $items): array
    {
        return $this->service->reserveStock($items);
    }

    /**
     * items: [{ product_id, quantity }]
     */
    public function releaseStock(array $items): void
    {
        $this->service->releaseStock($items);
    }
}
