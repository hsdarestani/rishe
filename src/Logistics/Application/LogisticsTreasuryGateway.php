<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface LogisticsTreasuryGateway
{
    /** @return array<string, mixed>|null */
    public function transactionForUpdate(int $transactionId): ?array;

    /** @return array<string, mixed> */
    public function matchShipmentCost(
        int $transactionId,
        int $shipmentId,
        int $amountIrr,
        int $actorUserId
    ): array;
}
