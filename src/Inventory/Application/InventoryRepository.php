<?php

declare(strict_types=1);

namespace Rishe\Inventory\Application;

interface InventoryRepository
{
    /** @param array<string, mixed> $data */
    public function createWarehouse(array $data): int;

    /** @param array<string, mixed> $data */
    public function createProduct(array $data): int;

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;

    /** @param array<string, mixed> $data */
    public function receive(array $data): int;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function reserve(array $data): array;

    /** @return array<string, mixed> */
    public function releaseReservation(int $reservationId, int $actorUserId): array;

    /** @return array<string, mixed> */
    public function commitReservation(int $reservationId, int $actorUserId): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function transfer(array $data): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function stockSummary(array $filters): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function ledger(array $filters): array;
}
