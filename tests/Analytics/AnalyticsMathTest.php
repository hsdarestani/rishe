<?php

declare(strict_types=1);

namespace Rishe\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Rishe\Analytics\Domain\AnalyticsMath;
use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;

final class AnalyticsMathTest extends TestCase
{
    public function testGrossProfitMarginAndAchievementUseIntegerMath(): void
    {
        $math = new AnalyticsMath();

        self::assertSame(300000, $math->grossProfit(1000000, 700000));
        self::assertSame(3000, $math->marginBasisPoints(1000000, 300000));
        self::assertSame(12500, $math->achievementBasisPoints(125, 100));
        self::assertSame(-2500, $math->marginBasisPoints(100, -25));
    }

    public function testZeroOrNegativeTargetIsRejected(): void
    {
        $this->expectException(AnalyticsDomainException::class);
        (new AnalyticsMath())->achievementBasisPoints(1, 0);
    }
}
