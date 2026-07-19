<?php

declare(strict_types=1);

namespace Rishe\Logistics\Domain;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

final class ShipmentCostVariance
{
    public static function calculate(int $chargedIrr, int $actualIrr): int
    {
        if ($chargedIrr < 0 || $actualIrr < 0) {
            throw new LogisticsDomainException('Shipping amounts must be non-negative integer IRR values.');
        }

        return $actualIrr - $chargedIrr;
    }

    public static function assertSettlement(int $actualIrr, int $alreadySettledIrr, int $amountIrr): void
    {
        if ($actualIrr < 0 || $alreadySettledIrr < 0 || $amountIrr < 1) {
            throw new LogisticsDomainException('Shipping settlement values are invalid.');
        }
        if ($alreadySettledIrr + $amountIrr > $actualIrr) {
            throw new LogisticsDomainException('Shipping settlement exceeds the recorded carrier cost.');
        }
    }
}
