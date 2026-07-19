<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics;

use PHPUnit\Framework\TestCase;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\ShipmentCostVariance;

final class ShipmentCostVarianceTest extends TestCase
{
    public function testPositiveVarianceMeansCarrierCostExceedsCustomerCharge(): void
    {
        self::assertSame(5000, ShipmentCostVariance::calculate(20000, 25000));
    }

    public function testSettlementCannotExceedActualCost(): void
    {
        $this->expectException(LogisticsDomainException::class);
        ShipmentCostVariance::assertSettlement(25000, 20000, 6000);
    }
}
