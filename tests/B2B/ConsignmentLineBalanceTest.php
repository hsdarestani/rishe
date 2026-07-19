<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B;

use PHPUnit\Framework\TestCase;
use Rishe\B2B\Domain\ConsignmentLineBalance;
use Rishe\B2B\Domain\Exception\B2BDomainException;

final class ConsignmentLineBalanceTest extends TestCase
{
    public function testSoldGoodsCannotBeReturned(): void
    {
        $this->expectException(B2BDomainException::class);
        (new ConsignmentLineBalance())->assertCanReturn(100000, 40000, 30000, 40000);
    }
}
