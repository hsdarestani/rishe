<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Rishe\Tax\Domain\TaxTotals;

final class TaxTotalsTest extends TestCase
{
    public function testCalculatesExactIntegerIrrTotals(): void
    {
        $totals = (new TaxTotals())->calculate([
            ['gross_irr' => 100000, 'discount_irr' => 10000, 'vat_irr' => 9000, 'total_irr' => 99000],
            ['gross_irr' => 200000, 'discount_irr' => 0, 'vat_irr' => 20000, 'total_irr' => 220000],
        ]);

        self::assertSame([
            'gross_irr' => 300000,
            'discount_irr' => 10000,
            'net_irr' => 290000,
            'vat_irr' => 29000,
            'total_irr' => 319000,
        ], $totals);
    }
}
