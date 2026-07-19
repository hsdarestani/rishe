<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

interface ProcurementAccountingGateway
{
    /** @param array<string, mixed> $receipt @return array{voucher_id: int, voucher_number: int}|null */
    public function postReceipt(array $receipt, int $actorUserId): ?array;

    /**
     * @param array<string, mixed> $purchaseOrder
     * @param array<string, mixed> $treasuryTransaction
     * @return array{voucher_id: int, voucher_number: int}|null
     */
    public function postPayment(
        array $purchaseOrder,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array;
}
