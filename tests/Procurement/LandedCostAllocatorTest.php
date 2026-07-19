<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement;

use PHPUnit\Framework\TestCase;
use Rishe\Procurement\Domain\LandedCostAllocator;

final class LandedCostAllocatorTest extends TestCase
{
    public function testValueAllocationPreservesExactTotal(): void
    {
        $allocated = (new LandedCostAllocator())->allocate(1001, [
            ['quantity_scaled' => 10000, 'merchandise_value_irr' => 3000],
            ['quantity_scaled' => 20000, 'merchandise_value_irr' => 7000],
        ], 'value');

        self::assertSame([300, 701], $allocated);
        self::assertSame(1001, array_sum($allocated));
    }

    public function testQuantityAllocationUsesScaledQuantities(): void
    {
        $allocated = (new LandedCostAllocator())->allocate(900, [
            ['quantity_scaled' => 10000, 'merchandise_value_irr' => 1],
            ['quantity_scaled' => 20000, 'merchandise_value_irr' => 1],
        ], 'quantity');

        self::assertSame([300, 600], $allocated);
    }
}
