<?php

namespace App\Modules\Products;

/**
 * Public face of the products module.
 * Other code may only use this class, never the classes next to it.
 */
class ProductsModule
{
    public function __construct(private ProductsService $service)
    {
    }

    /**
     * Name and price for an order's lines, copied at order time so later
     * product edits never change past orders. Throws the documented 404 when
     * any product id is unknown.
     *
     * items: [{ product_id, quantity }] -> [{ product_id, name, price, quantity }]
     */
    public function getOrderLines(array $items): array
    {
        return $this->service->getOrderLines($items);
    }
}
