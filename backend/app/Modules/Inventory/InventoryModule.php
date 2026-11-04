<?php

namespace App\Modules\Inventory;

use App\Shared\InventoryError;
use App\Shared\InventoryTimeout;
use App\Shared\InventoryUnavailable;

use Inventory\V1\AdjustStockRequest;
use Inventory\V1\AdjustStockResponse;
use Inventory\V1\GetStockRequest;
use Inventory\V1\GetStockResponse;
use Inventory\V1\ReleaseStockRequest;
use Inventory\V1\ReleaseStockResponse;
use Inventory\V1\ReservationItem;
use Inventory\V1\ReserveStockRequest;
use Inventory\V1\ReserveStockResponse;

/**
 * Public face of the inventory gateway module: the only way the app talks to
 * the inventory service. Other code may only use this class, never the
 * classes next to it.
 *
 * Methods throw InventoryUnavailable / InventoryTimeout / InventoryError when
 * the call itself fails; a refusal (shortage, already released, below zero)
 * is a normal return value.
 */
class InventoryModule
{
    private ?GrpcChannel $channel = null;

    private function channel(): GrpcChannel
    {
        return $this->channel ??= new GrpcChannel(
            (string) config('store.inventory.addr'),
            (int) config('store.inventory.timeout_ms'),
            (int) config('store.inventory.connect_timeout_ms'),
        );
    }

    /**
     * Available quantities, one batched call. Unknown products report 0.
     *
     * @param list<string> $productIds
     * @return array<string, int> product_id => available
     */
    public function getStock(array $productIds): array
    {
        $request = (new GetStockRequest)->setProductIds($productIds);
        $response = new GetStockResponse;
        $this->channel()->call('/inventory.v1.Inventory/GetStock', $request, $response);

        $levels = [];
        foreach ($response->getLevels() as $level) {
            $levels[$level->getProductId()] = $level->getAvailable();
        }

        return $levels;
    }

    /**
     * All-or-nothing reservation. $reservationId is the idempotency key (the
     * order's UUID).
     *
     * @param list<array{product_id: string, quantity: int}> $items
     * @return array{status: 'reserved'|'refused'|'already_released', shortage: ?array{product_id: string, requested: int, available: int}}
     */
    public function reserve(string $reservationId, array $items): array
    {
        $request = (new ReserveStockRequest)
            ->setReservationId($reservationId)
            ->setItems(array_map(
                fn (array $item) => (new ReservationItem)
                    ->setProductId($item['product_id'])
                    ->setQuantity($item['quantity']),
                $items,
            ));
        $response = new ReserveStockResponse;
        $this->channel()->call('/inventory.v1.Inventory/ReserveStock', $request, $response);

        $shortage = $response->getShortage();

        return [
            'status' => match ($response->getStatus()) {
                ReserveStockResponse\Status::RESERVED => 'reserved',
                ReserveStockResponse\Status::REFUSED => 'refused',
                ReserveStockResponse\Status::ALREADY_RELEASED => 'already_released',
                default => throw new InventoryError('unexpected ReserveStock status '.$response->getStatus()),
            },
            'shortage' => $shortage === null ? null : [
                'product_id' => $shortage->getProductId(),
                'requested' => $shortage->getRequested(),
                'available' => $shortage->getAvailable(),
            ],
        ];
    }

    /**
     * Returns a reservation's stock. Idempotent; an unknown id leaves a
     * tombstone on the inventory side.
     *
     * @return 'released'|'already_released'|'not_found'
     */
    public function release(string $reservationId): string
    {
        $request = (new ReleaseStockRequest)->setReservationId($reservationId);
        $response = new ReleaseStockResponse;
        $this->channel()->call('/inventory.v1.Inventory/ReleaseStock', $request, $response);

        return match ($response->getStatus()) {
            ReleaseStockResponse\Status::RELEASED => 'released',
            ReleaseStockResponse\Status::ALREADY_RELEASED => 'already_released',
            ReleaseStockResponse\Status::NOT_FOUND => 'not_found',
            default => throw new InventoryError('unexpected ReleaseStock status '.$response->getStatus()),
        };
    }

    /**
     * Relative stock change (admin restocking / initial stock).
     *
     * @return array{status: 'applied'|'refused', available: int}
     */
    public function adjust(string $productId, int $change): array
    {
        $request = (new AdjustStockRequest)->setProductId($productId)->setChange($change);
        $response = new AdjustStockResponse;
        $this->channel()->call('/inventory.v1.InventoryAdmin/AdjustStock', $request, $response);

        return [
            'status' => match ($response->getStatus()) {
                AdjustStockResponse\Status::APPLIED => 'applied',
                AdjustStockResponse\Status::REFUSED => 'refused',
                default => throw new InventoryError('unexpected AdjustStock status '.$response->getStatus()),
            },
            'available' => $response->getAvailable(),
        ];
    }
}
