<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

interface InventoryGateway
{
    public function reserve(
        int $productId,
        int $warehouseId,
        int $quantityScaled,
        string $orderKey,
        int $lineId,
        int $actorUserId,
        ?string $correlationId
    ): int;

    /** @return array{quantity_scaled: int, cogs_irr: int} */
    public function commit(int $reservationId, int $actorUserId): array;

    public function release(int $reservationId, int $actorUserId): void;
}
