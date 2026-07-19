<?php

declare(strict_types=1);

namespace Rishe\Tests\Inventory\Fakes;

use Rishe\Inventory\Application\InventoryRepository;

final class InMemoryInventoryRepository implements InventoryRepository
{
    /** @var array<string, mixed>|null */
    public ?array $productRow = [
        'id' => 10,
        'is_active' => true,
        'inventory_method' => 'fifo',
    ];

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public function createWarehouse(array $data): int
    {
        $this->lastPayload = $data;

        return 1;
    }

    public function createProduct(array $data): int
    {
        $this->lastPayload = $data;

        return 10;
    }

    public function product(int $productId): ?array
    {
        return $this->productRow;
    }

    public function receive(array $data): int
    {
        $this->lastPayload = $data;

        return 21;
    }

    public function reserve(array $data): array
    {
        $this->lastPayload = $data;

        return ['id' => 31, 'idempotent' => false];
    }

    public function releaseReservation(int $reservationId, int $actorUserId): array
    {
        return ['quantity_scaled' => 25000, 'correlation_id' => 'order-1'];
    }

    public function commitReservation(int $reservationId, int $actorUserId): array
    {
        return ['quantity_scaled' => 25000, 'cogs_irr' => 450000, 'correlation_id' => 'order-1'];
    }

    public function transfer(array $data): array
    {
        $this->lastPayload = $data;

        return [
            'quantity_scaled' => $data['quantity_scaled'],
            'inventory_value_irr' => 500000,
            'transfer_group_id' => 'transfer-1',
        ];
    }

    public function stockSummary(array $filters): array
    {
        return [];
    }

    public function ledger(array $filters): array
    {
        return [];
    }
}
