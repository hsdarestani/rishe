<?php

declare(strict_types=1);

namespace Rishe\B2B\Domain;

use Rishe\B2B\Domain\Exception\B2BDomainException;

final class CreditExposure
{
    public function assertCanCharge(int $currentReceivableIrr, int $newChargeIrr, int $creditLimitIrr): void
    {
        if ($currentReceivableIrr < 0 || $newChargeIrr < 0 || $creditLimitIrr < 1) {
            throw new B2BDomainException('Credit exposure inputs are invalid.');
        }
        if ($currentReceivableIrr + $newChargeIrr > $creditLimitIrr) {
            throw new B2BDomainException('B2B credit limit would be exceeded.');
        }
    }

    public function residual(int $receivableIrr, int $creditLimitIrr): int
    {
        return max(0, $creditLimitIrr - $receivableIrr);
    }
}
