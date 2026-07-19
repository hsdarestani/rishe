<?php

declare(strict_types=1);

namespace Rishe\Tests\Inventory;

use PHPUnit\Framework\TestCase;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\Quantity;

final class QuantityTest extends TestCase
{
    public function testDecimalQuantityUsesFourDigitScale(): void
    {
        $quantity = Quantity::fromInput('12.3456');

        self::assertSame(123456, $quantity->scaled());
        self::assertSame('12.3456', $quantity->decimal());
    }

    public function testTrailingZerosAreRemovedWhenFormatting(): void
    {
        self::assertSame('3.5', Quantity::fromScaled(35000)->decimal());
    }

    public function testMoreThanFourDecimalsAreRejected(): void
    {
        $this->expectException(InventoryDomainException::class);

        Quantity::fromInput('1.00001');
    }
}
