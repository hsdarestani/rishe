<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Rishe\Tax\Domain\Exception\TaxDomainException;
use Rishe\Tax\Domain\TaxInvoiceStatus;

final class TaxInvoiceStatusTest extends TestCase
{
    public function testAcceptedInvoiceCannotBeSubmittedAgain(): void
    {
        $this->expectException(TaxDomainException::class);
        TaxInvoiceStatus::ACCEPTED->assertCanSubmit();
    }
}
