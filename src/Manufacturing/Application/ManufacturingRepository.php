<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Application;

interface ManufacturingRepository
{
    /** @param array<string, mixed> $data */
    public function createBom(array $data): int;

    /** @return array<string, mixed> */
    public function activateBom(int $bomId, int $actorUserId): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function executeProduction(array $data): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function boms(array $filters): array;

    /** @return array<string, mixed>|null */
    public function productionOrder(int $orderId): ?array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function productionOrders(array $filters): array;
}
