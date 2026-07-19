<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B;

use PHPUnit\Framework\TestCase;
use Rishe\B2B\Domain\CreditExposure;
use Rishe\B2B\Domain\Exception\B2BDomainException;

final class CreditExposureTest extends TestCase
{
    public function testChargeCannotExceedCreditLimit(): void
    {
        $this->expectException(B2BDomainException::class);
        (new CreditExposure())->assertCanCharge(800000, 250000, 1000000);
    }
}
