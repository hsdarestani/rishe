<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Domain;

use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;

final class OutputQuantityPlanner
{
    /**
     * @param list<array<string, mixed>> $outputs
     * @return list<array<string, mixed>>
     */
    public function plan(array $outputs, int $requestedMainScaled): array
    {
        if ($requestedMainScaled < 1) {
            throw new ManufacturingDomainException('Requested main output quantity must be positive.');
        }

        $main = null;
        foreach ($outputs as $output) {
            if ((string) ($output['output_type'] ?? '') === 'main') {
                if ($main !== null) {
                    throw new ManufacturingDomainException('A joint BOM must contain exactly one main output.');
                }
                $main = $output;
            }
        }
        if ($main === null || (int) ($main['quantity_scaled'] ?? 0) < 1) {
            throw new ManufacturingDomainException('A positive main BOM output is required.');
        }

        $baseline = (int) $main['quantity_scaled'];
        $planned = [];
        foreach ($outputs as $output) {
            $bomQuantity = (int) ($output['quantity_scaled'] ?? 0);
            if ($bomQuantity < 1) {
                throw new ManufacturingDomainException('Every BOM output quantity must be positive.');
            }
            $quantity = $this->scaledRatio($bomQuantity, $requestedMainScaled, $baseline);
            if ($quantity < 1) {
                throw new ManufacturingDomainException('Requested production quantity makes an output round to zero.');
            }
            $output['planned_quantity_scaled'] = $quantity;
            $planned[] = $output;
        }

        return $planned;
    }

    private function scaledRatio(int $value, int $numerator, int $denominator): int
    {
        if ($value > intdiv(PHP_INT_MAX, $numerator)) {
            throw new ManufacturingDomainException('Output quantity calculation exceeds integer capacity.');
        }

        return intdiv(($value * $numerator) + intdiv($denominator, 2), $denominator);
    }
}
