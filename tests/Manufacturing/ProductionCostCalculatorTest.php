<?php

declare(strict_types=1);

namespace Rishe\Tests\Manufacturing;

use PHPUnit\Framework\TestCase;
use Rishe\Manufacturing\Domain\ProductionCostCalculator;

final class ProductionCostCalculatorTest extends TestCase
{
    public function testRequirementScalesOutputAndAddsWaste(): void
    {
        $calculator = new ProductionCostCalculator();

        $requirement = $calculator->requirement(25000, 100000, 250000, 500);

        self::assertSame(62500, $requirement['standard_scaled']);
        self::assertSame(3125, $requirement['waste_scaled']);
        self::assertSame(65625, $requirement['total_scaled']);
    }

    public function testAllocationsAreSplitBetweenStandardUseAndWaste(): void
    {
        $calculator = new ProductionCostCalculator();

        $rows = $calculator->splitAllocations([
            ['batch_id' => 1, 'quantity_scaled' => 50000, 'unit_cost_irr' => 100000, 'batch_code' => 'A'],
            ['batch_id' => 2, 'quantity_scaled' => 15625, 'unit_cost_irr' => 120000, 'batch_code' => 'B'],
        ], 62500);

        self::assertSame(50000, $rows[0]['standard_scaled']);
        self::assertSame(0, $rows[0]['waste_scaled']);
        self::assertSame(12500, $rows[1]['standard_scaled']);
        self::assertSame(3125, $rows[1]['waste_scaled']);
    }

    public function testExtendedAndFinishedUnitCostsUseScaledQuantities(): void
    {
        $calculator = new ProductionCostCalculator();

        self::assertSame(300000, $calculator->extendedCost(25000, 120000));
        self::assertSame(300000, $calculator->unitCost(750000, 25000));
    }
}
