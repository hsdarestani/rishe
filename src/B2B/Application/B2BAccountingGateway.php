<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

interface B2BAccountingGateway
{
    /** @param array<string, mixed> $report @return array{voucher_id: int, voucher_number: int}|null */
    public function postSalesReport(array $report, int $actorUserId): ?array;

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $treasuryTransaction
     * @return array{voucher_id: int, voucher_number: int}|null
     */
    public function postSettlement(
        array $account,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array;
}
