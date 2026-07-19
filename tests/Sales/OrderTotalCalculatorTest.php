<?php

declare(strict_types=1);

namespace Rishe\Tests\Sales;

use PHPUnit\Framework\TestCase;
use Rishe\Sales\Domain\OrderTotalCalculator;

final class OrderTotalCalculatorTest extends TestCase
{
    public function testItCombinesLinePromotionLoyaltyShippingAndTax(): void
    {
        $totals = (new OrderTotalCalculator())->calculate([
            ['quantity_scaled' => 20000, 'unit_price_irr' => 100000, 'line_discount_irr' => 10000],
            ['quantity_scaled' => 5000, 'unit_price_irr' => 200000, 'line_discount_irr' => 0],
        ], [
            'discount_type' => 'percent',
            'value' => 1000,
            'max_discount_irr' => null,
            'min_order_irr' => 0,
        ], 5000, 20000, 3000);

        self::assertSame(300000, $totals['gross_irr']);
        self::assertSame(290000, $totals['subtotal_irr']);
        self::assertSame(29000, $totals['promotion_discount_irr']);
        self::assertSame(279000, $totals['total_irr']);
    }
}
