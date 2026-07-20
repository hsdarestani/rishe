<?php

declare(strict_types=1);

namespace Rishe\Analytics\Domain;

use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;

final class AnalyticsMath
{
    public function grossProfit(int $revenueIrr, int $cogsIrr): int
    {
        if ($revenueIrr < 0 || $cogsIrr < 0) {
            throw new AnalyticsDomainException('Revenue and COGS must be non-negative integer IRR values.');
        }

        return $revenueIrr - $cogsIrr;
    }

    public function marginBasisPoints(int $revenueIrr, int $grossProfitIrr): int
    {
        if ($revenueIrr < 0) {
            throw new AnalyticsDomainException('Revenue must be non-negative.');
        }
        if ($revenueIrr === 0) {
            return 0;
        }

        return $this->ratioBasisPoints($grossProfitIrr, $revenueIrr);
    }

    public function achievementBasisPoints(int $actual, int $target): int
    {
        if ($target <= 0) {
            throw new AnalyticsDomainException('Target must be greater than zero.');
        }

        return $this->ratioBasisPoints($actual, $target);
    }

    private function ratioBasisPoints(int $numerator, int $denominator): int
    {
        $negative = $numerator < 0;
        $absolute = abs($numerator);
        $whole = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;
        $basisPoints = ($whole * 10000) + intdiv($remainder * 10000, $denominator);

        return $negative ? -$basisPoints : $basisPoints;
    }
}
