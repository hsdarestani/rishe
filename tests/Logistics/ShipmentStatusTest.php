<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics;

use PHPUnit\Framework\TestCase;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\ShipmentStatus;

final class ShipmentStatusTest extends TestCase
{
    public function testExceptionCanRecoverToInTransit(): void
    {
        ShipmentStatus::EXCEPTION->assertTransitionTo(ShipmentStatus::IN_TRANSIT);
        self::assertTrue(true);
    }

    public function testDeliveredShipmentCannotReturnToTransit(): void
    {
        $this->expectException(LogisticsDomainException::class);
        ShipmentStatus::DELIVERED->assertTransitionTo(ShipmentStatus::IN_TRANSIT);
    }
}
