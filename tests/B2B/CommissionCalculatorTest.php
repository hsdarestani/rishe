<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B;

use PHPUnit\Framework\TestCase;
use Rishe\B2B\Domain\CommissionCalculator;

final class CommissionCalculatorTest extends TestCase
{
    public function testCommissionUsesBasisPointsAndHalfUpRounding(): void
    {
        self::assertSame(1251, (new CommissionCalculator())->calculate(10005, 1250));
    }
}
