<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Domain;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;

final class JointCostAllocator
{
    /**
     * @param list<array<string, mixed>> $outputs
     * @return list<array<string, mixed>>
     */
    public function allocate(int $totalCostIrr, array $outputs, string $method): array
    {
        if ($totalCostIrr < 0) {
            throw new ManufacturingDomainException('Joint production cost cannot be negative.');
        }
        $method = strtolower(trim($method));
        if (!in_array($method, ['manual_basis_points', 'net_realizable_value'], true)) {
            throw new ManufacturingDomainException('Output cost allocation method is invalid.');
        }

        $weights = [];
        $inventoryIndexes = [];
        $weightTotal = 0;
        foreach ($outputs as $index => $output) {
            $type = (string) ($output['output_type'] ?? '');
            if (!in_array($type, ['main', 'byproduct', 'waste'], true)) {
                throw new ManufacturingDomainException('Output type must be main, byproduct, or waste.');
            }
            if ($type === 'waste') {
                $weights[$index] = 0;
                continue;
            }

            $inventoryIndexes[] = $index;
            $weight = $method === 'manual_basis_points'
                ? (int) ($output['allocation_basis_points'] ?? 0)
                : $this->netRealizableWeight($output);
            if ($weight < 1) {
                throw new ManufacturingDomainException('Every inventory-bearing output needs a positive allocation weight.');
            }
            $weights[$index] = $weight;
            $weightTotal = $this->checkedAdd($weightTotal, $weight);
        }
        if ($inventoryIndexes === []) {
            throw new ManufacturingDomainException('At least one inventory-bearing output is required.');
        }
        if ($method === 'manual_basis_points' && $weightTotal !== 10000) {
            throw new ManufacturingDomainException('Manual output allocation basis points must sum to 10000.');
        }
        if ($weightTotal < 1) {
            throw new ManufacturingDomainException('Output allocation weight total must be positive.');
        }

        $allocated = 0;
        $lastInventoryIndex = $inventoryIndexes[array_key_last($inventoryIndexes)];
        foreach ($outputs as $index => &$output) {
            $cost = 0;
            if (in_array($index, $inventoryIndexes, true)) {
                $cost = $index === $lastInventoryIndex
                    ? $totalCostIrr - $allocated
                    : $this->proportionalCost($totalCostIrr, $weights[$index], $weightTotal);
                $allocated = $this->checkedAdd($allocated, $cost);
            }
            $quantity = (int) ($output['planned_quantity_scaled'] ?? $output['quantity_scaled'] ?? 0);
            if ($quantity < 1) {
                throw new ManufacturingDomainException('Output quantity must be positive before cost allocation.');
            }
            $output['allocated_cost_irr'] = $cost;
            $output['unit_cost_irr'] = $cost === 0 ? 0 : $this->unitCost($cost, $quantity);
            $output['effective_allocation_basis_points'] = $totalCostIrr === 0
                ? 0
                : $this->proportionalCost(10000, $cost, $totalCostIrr);
        }
        unset($output);

        return array_values($outputs);
    }

    /** @param array<string, mixed> $output */
    private function netRealizableWeight(array $output): int
    {
        $quantity = (int) ($output['planned_quantity_scaled'] ?? $output['quantity_scaled'] ?? 0);
        $value = (int) ($output['allocation_value_irr'] ?? 0);
        if ($quantity < 1 || $value < 1) {
            throw new ManufacturingDomainException('NRV allocation requires positive quantity and unit value.');
        }
        if ($quantity > intdiv(PHP_INT_MAX, $value)) {
            throw new ManufacturingDomainException('NRV allocation weight exceeds integer capacity.');
        }

        return max(1, intdiv($quantity * $value, Quantity::SCALE));
    }

    private function proportionalCost(int $total, int $weight, int $weightTotal): int
    {
        $whole = intdiv($total, $weightTotal);
        $remainder = $total % $weightTotal;
        if ($remainder > 0 && $weight > intdiv(PHP_INT_MAX, $remainder)) {
            throw new ManufacturingDomainException('Joint cost allocation exceeds integer capacity.');
        }

        return ($whole * $weight) + intdiv($remainder * $weight, $weightTotal);
    }

    private function unitCost(int $totalCost, int $quantityScaled): int
    {
        if ($totalCost > intdiv(PHP_INT_MAX, Quantity::SCALE)) {
            throw new ManufacturingDomainException('Unit cost calculation exceeds integer capacity.');
        }

        return intdiv(($totalCost * Quantity::SCALE) + intdiv($quantityScaled, 2), $quantityScaled);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new ManufacturingDomainException('Cost allocation exceeds integer capacity.');
        }

        return $left + $right;
    }
}
