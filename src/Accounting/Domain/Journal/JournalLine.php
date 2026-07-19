<?php

declare(strict_types=1);

namespace Rishe\Accounting\Domain\Journal;

use Rishe\Accounting\Domain\Exception\AccountingDomainException;

final class JournalLine
{
    public function __construct(
        private readonly int $subsidiaryLedgerId,
        private readonly ?int $floatingDetailId,
        private readonly int $debit,
        private readonly int $credit,
        private readonly string $description = ''
    ) {
        if ($this->subsidiaryLedgerId < 1) {
            throw new AccountingDomainException('A journal line requires a valid subsidiary ledger.');
        }

        if ($this->debit < 0 || $this->credit < 0) {
            throw new AccountingDomainException('Debit and credit amounts cannot be negative.');
        }

        if (($this->debit === 0 && $this->credit === 0) || ($this->debit > 0 && $this->credit > 0)) {
            throw new AccountingDomainException('A journal line must contain either a debit or a credit amount.');
        }
    }

    public function subsidiaryLedgerId(): int
    {
        return $this->subsidiaryLedgerId;
    }

    public function floatingDetailId(): ?int
    {
        return $this->floatingDetailId;
    }

    public function debit(): int
    {
        return $this->debit;
    }

    public function credit(): int
    {
        return $this->credit;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'subsidiary_ledger_id' => $this->subsidiaryLedgerId,
            'floating_detail_id' => $this->floatingDetailId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
        ];
    }
}
