<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

interface ProcurementTreasuryGateway
{
    /** @return array<string, mixed>|null */
    public function transactionForUpdate(int $transactionId): ?array;

    /** @return array<string, mixed> */
    public function matchPurchase(
        int $transactionId,
        int $purchaseOrderId,
        int $amountIrr,
        int $actorUserId
    ): array;
}
