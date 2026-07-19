<?php

declare(strict_types=1);

namespace Rishe\Accounting\Application;

use Rishe\Accounting\Domain\Journal\JournalLine;

interface AccountingRepository
{
    /** @param array<string, mixed> $data */
    public function createAccountGroup(array $data): int;

    /** @param array<string, mixed> $data */
    public function createGeneralLedger(array $data): int;

    /** @param array<string, mixed> $data */
    public function createSubsidiaryLedger(array $data): int;

    /** @param array<string, mixed> $data */
    public function createFloatingDetail(array $data): int;

    /** @return array<string, mixed> */
    public function chart(): array;

    /**
     * @param list<int> $subsidiaryLedgerIds
     * @return array<int, array{requires_floating_detail: bool, is_active: bool}>
     */
    public function subsidiaryRules(array $subsidiaryLedgerIds): array;

    /**
     * @param list<JournalLine> $lines
     */
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
    ): int;

    /** @return array<string, mixed>|null */
    public function voucherForUpdate(int $voucherId): ?array;

    public function nextVoucherNumber(int $fiscalYear): int;

    public function markPosted(int $voucherId, int $voucherNumber, int $actorUserId, string $postedAt): void;

    public function markReversed(int $voucherId): void;

    public function findReversalId(int $voucherId): ?int;

    /** @return list<array<string, mixed>> */
    public function trialBalance(string $fromDate, string $toDate): array;
}
