<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface LogisticsAccountingGateway
{
    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $treasuryTransaction
     * @return array{voucher_id: int, voucher_number: int}|null
     */
    public function postCarrierSettlement(
        array $shipment,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array;
}
