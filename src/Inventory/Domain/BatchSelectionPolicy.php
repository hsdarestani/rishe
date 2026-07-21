<?php

declare(strict_types=1);

namespace Rishe\Inventory\Domain;

use Rishe\Inventory\Domain\Exception\InventoryDomainException;

final class BatchSelectionPolicy
{
    /**
     * @param list<array<string, mixed>> $batches
     * @return list<array<string, mixed>>
     */
    public function sort(array $batches, string $method): array
    {
        $method = strtolower(trim($method));
        if (!in_array($method, ['fefo', 'fifo', 'lifo'], true)) {
            throw new InventoryDomainException('Inventory allocation method must be fefo, fifo, or lifo.');
        }

        usort($batches, function (array $left, array $right) use ($method): int {
            if ($method === 'fefo') {
                $expiry = $this->compareNullableDates(
                    $left['expiry_date'] ?? null,
                    $right['expiry_date'] ?? null
                );
                if ($expiry !== 0) {
                    return $expiry;
                }
            }

            $received = strcmp((string) ($left['received_at'] ?? ''), (string) ($right['received_at'] ?? ''));
            if ($received !== 0) {
                return $method === 'lifo' ? -$received : $received;
            }

            $id = (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);

            return $method === 'lifo' ? -$id : $id;
        });

        return array_values($batches);
    }

    private function compareNullableDates(mixed $left, mixed $right): int
    {
        $leftDate = $left === null || $left === '' ? null : (string) $left;
        $rightDate = $right === null || $right === '' ? null : (string) $right;
        if ($leftDate === $rightDate) {
            return 0;
        }
        if ($leftDate === null) {
            return 1;
        }
        if ($rightDate === null) {
            return -1;
        }

        return strcmp($leftDate, $rightDate);
    }
}
