<?php

declare(strict_types=1);

namespace Rishe\Tests\Support;

use Rishe\Accounting\Application\AccountingRepository;
use Rishe\Accounting\Domain\Journal\JournalLine;

final class InMemoryAccountingRepository implements AccountingRepository
{
    /** @var array<int, array{requires_floating_detail: bool, is_active: bool}> */
    public array $rules = [];

    /** @var array<string, mixed> */
    public array $insertedVoucher = [];

    /** @var array<string, mixed>|null */
    public ?array $voucher = null;

    /** @var array{voucher_id: int, voucher_number: int, actor_user_id: int}|null */
    public ?array $posted = null;

    public int $nextNumber = 1;

    public function createAccountGroup(array $data): int
    {
        return 1;
    }

    public function createGeneralLedger(array $data): int
    {
        return 2;
    }

    public function createSubsidiaryLedger(array $data): int
    {
        return 3;
    }

    public function createFloatingDetail(array $data): int
    {
        return 4;
    }

    public function chart(): array
    {
        return [];
    }

    public function subsidiaryRules(array $subsidiaryLedgerIds): array
    {
        return $this->rules;
    }

    public function insertVoucher(
        int $fiscalYear,
        string $voucherDate,
        string $status,
        string $description,
        int $totalDebit,
        int $totalCredit,
        array $lines,
        ?int $voucherNumber,
        ?int $reversalOfId,
        ?string $correlationId,
        ?int $postedBy,
        ?string $postedAt
    ): int {
        $this->insertedVoucher = [
            'fiscal_year' => $fiscalYear,
            'voucher_date' => $voucherDate,
            'status' => $status,
            'description' => $description,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'lines' => array_map(static fn (JournalLine $line): array => $line->toArray(), $lines),
            'voucher_number' => $voucherNumber,
            'reversal_of_id' => $reversalOfId,
            'correlation_id' => $correlationId,
            'posted_by' => $postedBy,
            'posted_at' => $postedAt,
        ];

        return 77;
    }

    public function voucherForUpdate(int $voucherId): ?array
    {
        return $this->voucher;
    }

    public function nextVoucherNumber(int $fiscalYear): int
    {
        return $this->nextNumber;
    }

    public function markPosted(int $voucherId, int $voucherNumber, int $actorUserId, string $postedAt): void
    {
        $this->posted = [
            'voucher_id' => $voucherId,
            'voucher_number' => $voucherNumber,
            'actor_user_id' => $actorUserId,
        ];
    }

    public function markReversed(int $voucherId): void
    {
    }

    public function findReversalId(int $voucherId): ?int
    {
        return null;
    }

    public function trialBalance(string $fromDate, string $toDate): array
    {
        return [];
    }
}
