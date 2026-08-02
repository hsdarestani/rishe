<?php

declare(strict_types=1);

namespace Rishe\Tests\Manufacturing;

use PHPUnit\Framework\TestCase;
use Rishe\Manufacturing\Domain\OutputQuantityPlanner;

final class OutputQuantityPlannerTest extends TestCase
{
    public function testEveryOutputScalesFromRequestedMainQuantity(): void
    {
        $planned = (new OutputQuantityPlanner())->plan([
            ['product_id' => 10, 'output_type' => 'main', 'quantity_scaled' => 40000],
            ['product_id' => 11, 'output_type' => 'byproduct', 'quantity_scaled' => 8000],
            ['product_id' => 12, 'output_type' => 'waste', 'quantity_scaled' => 2000],
        ], 100000);

        self::assertSame([100000, 20000, 5000], array_column($planned, 'planned_quantity_scaled'));
    }
}
