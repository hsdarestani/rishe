<?php

declare(strict_types=1);

namespace Rishe\Inventory\Domain;

use Rishe\Inventory\Domain\Exception\InventoryDomainException;

final class FifoAllocator
{
    /**
     * @param list<array{id: int, available_scaled: int, unit_cost_irr: int, batch_code: string}> $batches
     * @return list<array{batch_id: int, quantity_scaled: int, unit_cost_irr: int, batch_code: string}>
     */
    public function allocate(array $batches, int $requiredScaled): array
    {
        if ($requiredScaled < 1) {
            throw new InventoryDomainException('Allocation quantity must be greater than zero.');
        }

        $remaining = $requiredScaled;
        $allocations = [];

        foreach ($batches as $batch) {
            $available = max(0, (int) $batch['available_scaled']);
            if ($available === 0) {
                continue;
            }

            $allocated = min($available, $remaining);
            $allocations[] = [
                'batch_id' => (int) $batch['id'],
                'quantity_scaled' => $allocated,
                'unit_cost_irr' => (int) $batch['unit_cost_irr'],
                'batch_code' => (string) $batch['batch_code'],
            ];
            $remaining -= $allocated;

            if ($remaining === 0) {
                return $allocations;
            }
        }

        throw new InventoryDomainException('Insufficient available stock for this operation.');
    }
}
