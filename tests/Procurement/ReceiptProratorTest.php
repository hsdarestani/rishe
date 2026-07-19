<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement;

use PHPUnit\Framework\TestCase;
use Rishe\Procurement\Domain\ReceiptProrator;

final class ReceiptProratorTest extends TestCase
{
    public function testFinalReceiptReceivesRoundingRemainder(): void
    {
        $prorator = new ReceiptProrator();
        $first = $prorator->prorate(30000, 0, 10000, 100, 10, 0, 0);
        $final = $prorator->prorate(
            30000,
            10000,
            20000,
            100,
            10,
            $first['inventory_value_irr'],
            $first['tax_irr']
        );

        self::assertSame(100, $first['inventory_value_irr'] + $final['inventory_value_irr']);
        self::assertSame(10, $first['tax_irr'] + $final['tax_irr']);
    }
}
