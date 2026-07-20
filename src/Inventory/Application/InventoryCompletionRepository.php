<?php

declare(strict_types=1);

namespace Rishe\Inventory\Application;

interface InventoryCompletionRepository
{
    /** @return list<int> */
    public function expiredReservationIds(
        int $limit,
        string $now,
        ?int $productId = null,
        ?int $warehouseId = null
    ): array;

    /** @return array<string, mixed> */
    public function updateAllocationMethod(int $productId, string $method, int $actorUserId): array;
}
