<?php

declare(strict_types=1);

namespace Rishe\Accounting\Domain\Journal;

use Rishe\Accounting\Domain\Exception\AccountingDomainException;

final class VoucherBalance
{
    /**
     * @param list<JournalLine> $lines
     * @return array{debit: int, credit: int}
     */
    public static function totals(array $lines): array
    {
        if (count($lines) < 2) {
            throw new AccountingDomainException('A voucher requires at least two journal lines.');
        }

        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            $debit += $line->debit();
            $credit += $line->credit();
        }

        if ($debit === 0 || $debit !== $credit) {
            throw new AccountingDomainException('The voucher is not balanced.');
        }

        return ['debit' => $debit, 'credit' => $credit];
    }
}
