<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Rishe\Tax\Domain\TaxInvoiceNumberGenerator;

final class TaxInvoiceNumberGeneratorTest extends TestCase
{
    public function testGeneratesFixedTwentyTwoCharacterIdentifier(): void
    {
        $number = (new TaxInvoiceNumberGenerator())->generate('ABC123', 1721433600, 15);

        self::assertSame(22, strlen($number));
        self::assertMatchesRegularExpression('/^[A-Z0-9]{21}[0-9]$/', $number);
        self::assertStringStartsWith('ABC123', $number);
    }
}
