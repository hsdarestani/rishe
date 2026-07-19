<?php

declare(strict_types=1);

namespace Rishe\Sales\Domain;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Sales\Domain\Exception\SalesDomainException;

final class OrderTotalCalculator
{
    /**
     * @param list<array{quantity_scaled: int, unit_price_irr: int, line_discount_irr: int}> $lines
     * @param array<string, mixed>|null $promotion
     * @return array<string, int>
     */
    public function calculate(
        array $lines,
        ?array $promotion,
        int $loyaltyDiscountIrr,
        int $shippingIrr,
        int $taxIrr
    ): array {
        if ($lines === []) {
            throw new SalesDomainException('An order must contain at least one line.');
        }
        foreach ([$loyaltyDiscountIrr, $shippingIrr, $taxIrr] as $amount) {
            if ($amount < 0) {
                throw new SalesDomainException('Order monetary values cannot be negative.');
            }
        }

        $gross = 0;
        $lineDiscount = 0;
        foreach ($lines as $line) {
            $quantity = (int) $line['quantity_scaled'];
            $unitPrice = (int) $line['unit_price_irr'];
            $discount = (int) $line['line_discount_irr'];
            if ($quantity < 1 || $unitPrice < 0 || $discount < 0) {
                throw new SalesDomainException('Order line quantities and monetary values are invalid.');
            }

            $lineGross = intdiv($quantity * $unitPrice, Quantity::SCALE);
            if ($discount > $lineGross) {
                throw new SalesDomainException('Line discount cannot exceed line gross amount.');
            }
            $gross += $lineGross;
            $lineDiscount += $discount;
        }

        $subtotal = $gross - $lineDiscount;
        $promotionDiscount = $this->promotionDiscount($subtotal, $promotion);
        $discountable = max(0, $subtotal - $promotionDiscount);
        $loyaltyDiscount = min($loyaltyDiscountIrr, $discountable);
        $total = $discountable - $loyaltyDiscount + $shippingIrr + $taxIrr;

        return [
            'gross_irr' => $gross,
            'line_discount_irr' => $lineDiscount,
            'subtotal_irr' => $subtotal,
            'promotion_discount_irr' => $promotionDiscount,
            'loyalty_discount_irr' => $loyaltyDiscount,
            'shipping_irr' => $shippingIrr,
            'tax_irr' => $taxIrr,
            'total_irr' => $total,
        ];
    }

    /** @param array<string, mixed>|null $promotion */
    private function promotionDiscount(int $subtotal, ?array $promotion): int
    {
        if ($promotion === null || $subtotal === 0) {
            return 0;
        }

        $minimum = (int) ($promotion['min_order_irr'] ?? 0);
        if ($subtotal < $minimum) {
            throw new SalesDomainException('The promotion minimum order amount has not been reached.');
        }

        $type = (string) ($promotion['discount_type'] ?? '');
        $value = (int) ($promotion['value'] ?? 0);
        if ($type === 'fixed') {
            $discount = $value;
        } elseif ($type === 'percent') {
            if ($value < 0 || $value > 10000) {
                throw new SalesDomainException('Promotion percentage is invalid.');
            }
            $discount = intdiv($subtotal * $value, 10000);
        } else {
            throw new SalesDomainException('Promotion discount type is invalid.');
        }

        $maximum = $promotion['max_discount_irr'] ?? null;
        if ($maximum !== null) {
            $discount = min($discount, (int) $maximum);
        }

        return min(max(0, $discount), $subtotal);
    }
}
