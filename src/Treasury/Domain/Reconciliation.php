<?php

declare(strict_types=1);

namespace Rishe\Treasury\Domain;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;

final class Reconciliation
{
    public static function residual(int $transactionAmount, int $matchedAmount): int
    {
        if ($transactionAmount < 1 || $matchedAmount < 0 || $matchedAmount > $transactionAmount) {
            throw new TreasuryDomainException('Treasury reconciliation amounts are invalid.');
        }

        return $transactionAmount - $matchedAmount;
    }

    public static function assertMatch(int $transactionAmount, int $matchedAmount, int $requestedAmount): void
    {
        if ($requestedAmount < 1) {
            throw new TreasuryDomainException('Reconciliation match amount must be greater than zero.');
        }
        if ($requestedAmount > self::residual($transactionAmount, $matchedAmount)) {
            throw new TreasuryDomainException('Reconciliation match exceeds the unmatched transaction amount.');
        }
    }
}
