<?php

declare(strict_types=1);

namespace Rishe\Procurement\Domain;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;

final class LandedCostAllocator
{
    /**
     * @param list<array{quantity_scaled: int, merchandise_value_irr: int}> $lines
     * @return list<int>
     */
    public function allocate(int $amountIrr, array $lines, string $basis = 'value'): array
    {
        if ($amountIrr < 0) {
            throw new ProcurementDomainException('Landed cost must be non-negative.');
        }
        if ($lines === []) {
            throw new ProcurementDomainException('Landed cost allocation requires at least one receipt line.');
        }
        if (!in_array($basis, ['value', 'quantity'], true)) {
            throw new ProcurementDomainException('Landed cost allocation basis must be value or quantity.');
        }

        $weights = [];
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity_scaled'] ?? 0);
            $value = (int) ($line['merchandise_value_irr'] ?? 0);
            if ($quantity < 1 || $value < 0) {
                throw new ProcurementDomainException('Receipt line weights are invalid.');
            }
            $weights[] = $basis === 'value' ? $value : $quantity;
        }

        $weightTotal = array_sum($weights);
        if ($weightTotal < 1) {
            $weights = array_map(static fn (): int => 1, $weights);
            $weightTotal = count($weights);
        }

        $allocations = [];
        $allocated = 0;
        $lastIndex = array_key_last($weights);
        foreach ($weights as $index => $weight) {
            $share = $index === $lastIndex
                ? $amountIrr - $allocated
                : intdiv($amountIrr * $weight, $weightTotal);
            $allocations[] = $share;
            $allocated += $share;
        }

        return $allocations;
    }
}
