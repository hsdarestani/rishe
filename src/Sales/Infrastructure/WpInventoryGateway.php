<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure;

use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Sales\Application\InventoryGateway;

final class WpInventoryGateway implements InventoryGateway
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function reserve(
        int $productId,
        int $warehouseId,
        int $quantityScaled,
        string $orderKey,
        int $lineId,
        int $actorUserId,
        ?string $correlationId
    ): int {
        return $this->inventory->reserveStock([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => Quantity::fromScaled($quantityScaled)->decimal(),
            'reference_type' => 'sales_order_line',
            'reference_id' => $orderKey . ':' . $lineId,
            'correlation_id' => $correlationId,
        ], $actorUserId);
    }

    public function commit(int $reservationId, int $actorUserId): array
    {
        return $this->inventory->commitReservation($reservationId, $actorUserId);
    }

    public function release(int $reservationId, int $actorUserId): void
    {
        $this->inventory->releaseReservation($reservationId, $actorUserId);
    }
}
