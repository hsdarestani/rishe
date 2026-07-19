<?php

declare(strict_types=1);

namespace Rishe\Tests\Accounting;

use PHPUnit\Framework\TestCase;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Accounting\Domain\Journal\JournalLine;
use Rishe\Accounting\Domain\Journal\VoucherBalance;

final class VoucherBalanceTest extends TestCase
{
    public function testBalancedVoucherReturnsTotals(): void
    {
        $totals = VoucherBalance::totals([
            new JournalLine(1, null, 10000, 0),
            new JournalLine(2, null, 0, 10000),
        ]);

        self::assertSame(['debit' => 10000, 'credit' => 10000], $totals);
    }

    public function testUnbalancedVoucherIsRejected(): void
    {
        $this->expectException(AccountingDomainException::class);

        VoucherBalance::totals([
            new JournalLine(1, null, 10000, 0),
            new JournalLine(2, null, 0, 9000),
        ]);
    }

    public function testVoucherRequiresAtLeastTwoLines(): void
    {
        $this->expectException(AccountingDomainException::class);

        VoucherBalance::totals([new JournalLine(1, null, 10000, 0)]);
    }
}
