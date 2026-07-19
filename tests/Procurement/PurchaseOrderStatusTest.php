<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement;

use PHPUnit\Framework\TestCase;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use Rishe\Procurement\Domain\PurchaseOrderStatus;

final class PurchaseOrderStatusTest extends TestCase
{
    public function testReceivedOrderCannotBeCancelled(): void
    {
        $this->expectException(ProcurementDomainException::class);
        PurchaseOrderStatus::RECEIVED->assertCanCancel(true);
    }
}
