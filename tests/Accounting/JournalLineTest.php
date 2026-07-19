<?php

declare(strict_types=1);

namespace Rishe\Tests\Accounting;

use PHPUnit\Framework\TestCase;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Accounting\Domain\Journal\JournalLine;

final class JournalLineTest extends TestCase
{
    public function testDebitLineIsValid(): void
    {
        $line = new JournalLine(10, null, 5000, 0, 'Cash received');

        self::assertSame(10, $line->subsidiaryLedgerId());
        self::assertSame(5000, $line->debit());
        self::assertSame(0, $line->credit());
    }

    public function testLineCannotContainDebitAndCredit(): void
    {
        $this->expectException(AccountingDomainException::class);

        new JournalLine(10, null, 5000, 5000);
    }

    public function testLineCannotBeZeroSided(): void
    {
        $this->expectException(AccountingDomainException::class);

        new JournalLine(10, null, 0, 0);
    }

    public function testLineCannotContainNegativeAmount(): void
    {
        $this->expectException(AccountingDomainException::class);

        new JournalLine(10, null, -1, 0);
    }
}
