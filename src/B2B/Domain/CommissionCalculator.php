<?php

declare(strict_types=1);

namespace Rishe\B2B\Domain;

use Rishe\B2B\Domain\Exception\B2BDomainException;

final class CommissionCalculator
{
    public function calculate(int $grossIrr, int $rateBps): int
    {
        if ($grossIrr < 0) {
            throw new B2BDomainException('Gross amount must be non-negative.');
        }
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new B2BDomainException('Commission rate must be between 0 and 10000 basis points.');
        }

        return intdiv(($grossIrr * $rateBps) + 5000, 10000);
    }
}
