<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Domain;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;

final class ProductionCostCalculator
{
    /** @return array{standard_scaled: int, waste_scaled: int, total_scaled: int} */
    public function requirement(
        int $componentQuantityScaled,
        int $bomOutputQuantityScaled,
        int $actualOutputQuantityScaled,
        int $wasteBasisPoints
    ): array {
        if ($componentQuantityScaled < 1 || $bomOutputQuantityScaled < 1 || $actualOutputQuantityScaled < 1) {
            throw new ManufacturingDomainException('Production quantities must be greater than zero.');
        }
        if ($wasteBasisPoints < 0 || $wasteBasisPoints > 10000) {
            throw new ManufacturingDomainException('Waste basis points must be between zero and 10000.');
        }

        $standard = $this->ceilMultiplyDivide(
            $componentQuantityScaled,
            $actualOutputQuantityScaled,
            $bomOutputQuantityScaled
        );
        $waste = $wasteBasisPoints === 0
            ? 0
            : $this->ceilMultiplyDivide($standard, $wasteBasisPoints, 10000);

        if ($standard > PHP_INT_MAX - $waste) {
            throw new ManufacturingDomainException('Calculated production requirement exceeds the supported range.');
        }

        return [
            'standard_scaled' => $standard,
            'waste_scaled' => $waste,
            'total_scaled' => $standard + $waste,
        ];
    }

    /**
     * @param list<array{batch_id: int, quantity_scaled: int, unit_cost_irr: int, batch_code: string}> $allocations
     * @return list<array{batch_id: int, standard_scaled: int, waste_scaled: int, total_scaled: int, unit_cost_irr: int, batch_code: string}>
     */
    public function splitAllocations(array $allocations, int $standardRequiredScaled): array
    {
        if ($standardRequiredScaled < 1) {
            throw new ManufacturingDomainException('Standard production requirement must be greater than zero.');
        }

        $remainingStandard = $standardRequiredScaled;
        $result = [];
        foreach ($allocations as $allocation) {
            $total = (int) $allocation['quantity_scaled'];
            if ($total < 1) {
                throw new ManufacturingDomainException('Allocated production quantity must be greater than zero.');
            }

            $standard = min($total, $remainingStandard);
            $waste = $total - $standard;
            $remainingStandard -= $standard;
            $result[] = [
                'batch_id' => (int) $allocation['batch_id'],
                'standard_scaled' => $standard,
                'waste_scaled' => $waste,
                'total_scaled' => $total,
                'unit_cost_irr' => (int) $allocation['unit_cost_irr'],
                'batch_code' => (string) $allocation['batch_code'],
            ];
        }

        if ($remainingStandard !== 0) {
            throw new ManufacturingDomainException('Allocated batches do not cover the standard production requirement.');
        }

        return $result;
    }

    public function extendedCost(int $quantityScaled, int $unitCostIrr): int
    {
        if ($quantityScaled < 0 || $unitCostIrr < 0) {
            throw new ManufacturingDomainException('Production cost inputs cannot be negative.');
        }
        if ($quantityScaled === 0 || $unitCostIrr === 0) {
            return 0;
        }
        if ($quantityScaled > intdiv(PHP_INT_MAX, $unitCostIrr)) {
            throw new ManufacturingDomainException('Production cost exceeds the supported integer range.');
        }

        return intdiv($quantityScaled * $unitCostIrr, Quantity::SCALE);
    }

    public function unitCost(int $totalCostIrr, int $outputQuantityScaled): int
    {
        if ($totalCostIrr < 0 || $outputQuantityScaled < 1) {
            throw new ManufacturingDomainException('Finished-goods cost inputs are invalid.');
        }
        if ($totalCostIrr === 0) {
            return 0;
        }
        if ($totalCostIrr > intdiv(PHP_INT_MAX, Quantity::SCALE)) {
            throw new ManufacturingDomainException('Finished-goods unit cost exceeds the supported range.');
        }

        $numerator = $totalCostIrr * Quantity::SCALE;
        $half = intdiv($outputQuantityScaled, 2);
        if ($numerator > PHP_INT_MAX - $half) {
            throw new ManufacturingDomainException('Finished-goods unit cost exceeds the supported range.');
        }

        return intdiv($numerator + $half, $outputQuantityScaled);
    }

    private function ceilMultiplyDivide(int $left, int $right, int $divisor): int
    {
        if ($left < 0 || $right < 0 || $divisor < 1) {
            throw new ManufacturingDomainException('Production ratio inputs are invalid.');
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new ManufacturingDomainException('Production ratio exceeds the supported integer range.');
        }

        $product = $left * $right;

        return intdiv($product, $divisor) + ($product % $divisor === 0 ? 0 : 1);
    }
}
