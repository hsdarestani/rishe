<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

interface B2BTreasuryGateway
{
    /** @return array<string, mixed>|null */
    public function transactionForUpdate(int $transactionId): ?array;

    /** @return array<string, mixed> */
    public function matchAccount(
        int $transactionId,
        int $accountId,
        int $amountIrr,
        int $actorUserId
    ): array;
}
