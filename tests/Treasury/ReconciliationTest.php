<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury;

use PHPUnit\Framework\TestCase;
use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use Rishe\Treasury\Domain\Reconciliation;

final class ReconciliationTest extends TestCase
{
    public function testResidualIsCalculated(): void
    {
        self::assertSame(600, Reconciliation::residual(1000, 400));
    }

    public function testOverMatchingIsRejected(): void
    {
        $this->expectException(TreasuryDomainException::class);
        Reconciliation::assertMatch(1000, 700, 301);
    }
}
