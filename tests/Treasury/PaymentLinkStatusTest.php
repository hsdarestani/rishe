<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury;

use PHPUnit\Framework\TestCase;
use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use Rishe\Treasury\Domain\PaymentLinkStatus;

final class PaymentLinkStatusTest extends TestCase
{
    public function testActiveLinkCanBecomePaid(): void
    {
        self::assertTrue(PaymentLinkStatus::ACTIVE->canTransitionTo(PaymentLinkStatus::PAID));
    }

    public function testPaidLinkCannotBecomeActive(): void
    {
        $this->expectException(TreasuryDomainException::class);
        PaymentLinkStatus::PAID->assertTransition(PaymentLinkStatus::ACTIVE);
    }
}
