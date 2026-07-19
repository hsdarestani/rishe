<?php

declare(strict_types=1);

namespace Rishe\Procurement\Domain;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;

final class ReceiptProrator
{
    /**
     * @return array{inventory_value_irr: int, tax_irr: int, liability_irr: int}
     */
    public function prorate(
        int $orderedQuantityScaled,
        int $alreadyReceivedQuantityScaled,
        int $receiptQuantityScaled,
        int $lineInventoryValueIrr,
        int $lineTaxIrr,
        int $alreadyReceivedInventoryValueIrr,
        int $alreadyReceivedTaxIrr
    ): array {
        if ($orderedQuantityScaled < 1 || $receiptQuantityScaled < 1) {
            throw new ProcurementDomainException('Ordered and receipt quantities must be positive.');
        }
        if (
            $alreadyReceivedQuantityScaled < 0
            || $alreadyReceivedQuantityScaled + $receiptQuantityScaled > $orderedQuantityScaled
        ) {
            throw new ProcurementDomainException('Receipt quantity exceeds the outstanding purchase quantity.');
        }
        foreach ([$lineInventoryValueIrr, $lineTaxIrr, $alreadyReceivedInventoryValueIrr, $alreadyReceivedTaxIrr] as $value) {
            if ($value < 0) {
                throw new ProcurementDomainException('Purchase values must be non-negative.');
            }
        }

        $isFinalReceipt = $alreadyReceivedQuantityScaled + $receiptQuantityScaled === $orderedQuantityScaled;
        $inventoryValue = $isFinalReceipt
            ? $lineInventoryValueIrr - $alreadyReceivedInventoryValueIrr
            : intdiv($lineInventoryValueIrr * $receiptQuantityScaled, $orderedQuantityScaled);
        $tax = $isFinalReceipt
            ? $lineTaxIrr - $alreadyReceivedTaxIrr
            : intdiv($lineTaxIrr * $receiptQuantityScaled, $orderedQuantityScaled);

        if ($inventoryValue < 0 || $tax < 0) {
            throw new ProcurementDomainException('Stored received values exceed the purchase-order line totals.');
        }

        return [
            'inventory_value_irr' => $inventoryValue,
            'tax_irr' => $tax,
            'liability_irr' => $inventoryValue + $tax,
        ];
    }
}
