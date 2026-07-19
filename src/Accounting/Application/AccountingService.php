<?php

declare(strict_types=1);

namespace Rishe\Accounting\Application;

use DateTimeImmutable;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Accounting\Domain\Journal\JournalLine;
use Rishe\Accounting\Domain\Journal\VoucherBalance;
use Rishe\Accounting\Domain\Journal\VoucherStatus;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;

final class AccountingService
{
    public function __construct(
        private readonly AccountingRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createAccountGroup(array $data): int
    {
        $payload = $this->accountPayload($data);
        $id = $this->repository->createAccountGroup($payload);
        $this->audit->record('accounting.account_group.created', 'account_group', (string) $id, $payload);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function createGeneralLedger(array $data): int
    {
        $payload = $this->accountPayload($data);
        $payload['account_group_id'] = $this->positiveId($data['account_group_id'] ?? null, 'account_group_id');
        $id = $this->repository->createGeneralLedger($payload);
        $this->audit->record('accounting.general_ledger.created', 'general_ledger', (string) $id, $payload);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function createSubsidiaryLedger(array $data): int
    {
        $payload = $this->accountPayload($data);
        $payload['general_ledger_id'] = $this->positiveId($data['general_ledger_id'] ?? null, 'general_ledger_id');
        $payload['requires_floating_detail'] = (bool) ($data['requires_floating_detail'] ?? false);
        $id = $this->repository->createSubsidiaryLedger($payload);
        $this->audit->record('accounting.subsidiary_ledger.created', 'subsidiary_ledger', (string) $id, $payload);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function createFloatingDetail(array $data): int
    {
        $type = strtolower(trim((string) ($data['detail_type'] ?? '')));
        $allowedTypes = ['customer', 'vendor', 'person', 'bank', 'warehouse', 'branch', 'project', 'other'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new AccountingDomainException('The floating detail type is invalid.');
        }

        $payload = [
            'detail_type' => $type,
            'external_reference' => $this->nullableText($data['external_reference'] ?? null, 191),
            'code' => $this->code($data['code'] ?? null),
            'name' => $this->name($data['name'] ?? null),
            'mobile' => $this->nullableText($data['mobile'] ?? null, 20),
        ];
        $id = $this->repository->createFloatingDetail($payload);
        $this->audit->record('accounting.floating_detail.created', 'floating_detail', (string) $id, $payload);

        return $id;
    }

    /** @return array<string, mixed> */
    public function chart(): array
    {
        return $this->repository->chart();
    }

    /**
     * @param list<array<string, mixed>> $rawLines
     */
    public function createDraftVoucher(
        int $fiscalYear,
        string $voucherDate,
        string $description,
        array $rawLines,
        ?string $correlationId = null
    ): int {
        $this->assertFiscalYear($fiscalYear);
        $this->assertDate($voucherDate);
        $lines = $this->linesFromPayload($rawLines);
        $this->assertLedgerAssignments($lines);
        $totals = VoucherBalance::totals($lines);

        return $this->transactions->run(function () use (
            $fiscalYear,
            $voucherDate,
            $description,
            $lines,
            $totals,
            $correlationId
        ): int {
            $voucherId = $this->repository->insertVoucher(
                $fiscalYear,
                $voucherDate,
                VoucherStatus::DRAFT->value,
                trim($description),
                $totals['debit'],
                $totals['credit'],
                $lines,
                null,
                null,
                $correlationId,
                null,
                null
            );

            $this->audit->record(
                'accounting.voucher.created',
                'journal_voucher',
                (string) $voucherId,
                ['fiscal_year' => $fiscalYear, 'total' => $totals['debit']],
                $correlationId
            );

            return $voucherId;
        });
    }

    public function postVoucher(int $voucherId, int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new AccountingDomainException('Posting requires an authenticated actor.');
        }

        return $this->transactions->run(function () use ($voucherId, $actorUserId): int {
            $voucher = $this->repository->voucherForUpdate($voucherId);
            if ($voucher === null) {
                throw new RuntimeException('Voucher not found.');
            }

            $status = VoucherStatus::tryFrom((string) $voucher['status']);
            if ($status === null || !$status->isMutable()) {
                throw new AccountingDomainException('Only draft or temporary vouchers can be posted.');
            }

            $lines = $this->linesFromStoredVoucher($voucher);
            $this->assertLedgerAssignments($lines);
            $totals = VoucherBalance::totals($lines);
            if ($totals['debit'] !== (int) $voucher['total_debit'] || $totals['credit'] !== (int) $voucher['total_credit']) {
                throw new AccountingDomainException('Stored voucher totals do not match its journal entries.');
            }

            $voucherNumber = $this->repository->nextVoucherNumber((int) $voucher['fiscal_year']);
            $postedAt = gmdate('Y-m-d H:i:s');
            $this->repository->markPosted($voucherId, $voucherNumber, $actorUserId, $postedAt);
            $this->audit->record(
                'accounting.voucher.posted',
                'journal_voucher',
                (string) $voucherId,
                ['voucher_number' => $voucherNumber, 'total' => $totals['debit']],
                $this->nullableText($voucher['correlation_id'] ?? null, 64)
            );

            return $voucherNumber;
        });
    }

    public function reverseVoucher(
        int $voucherId,
        int $fiscalYear,
        string $voucherDate,
        string $description,
        int $actorUserId
    ): int {
        $this->assertFiscalYear($fiscalYear);
        $this->assertDate($voucherDate);
        if ($actorUserId < 1) {
            throw new AccountingDomainException('Reversal requires an authenticated actor.');
        }

        return $this->transactions->run(function () use (
            $voucherId,
            $fiscalYear,
            $voucherDate,
            $description,
            $actorUserId
        ): int {
            $voucher = $this->repository->voucherForUpdate($voucherId);
            if ($voucher === null) {
                throw new RuntimeException('Voucher not found.');
            }
            if ((string) $voucher['status'] !== VoucherStatus::POSTED->value) {
                throw new AccountingDomainException('Only a posted voucher can be reversed.');
            }
            if ($this->repository->findReversalId($voucherId) !== null) {
                throw new AccountingDomainException('This voucher has already been reversed.');
            }

            $originalLines = $this->linesFromStoredVoucher($voucher);
            $reversalLines = array_map(
                static fn (JournalLine $line): JournalLine => new JournalLine(
                    $line->subsidiaryLedgerId(),
                    $line->floatingDetailId(),
                    $line->credit(),
                    $line->debit(),
                    $line->description()
                ),
                $originalLines
            );
            $totals = VoucherBalance::totals($reversalLines);
            $voucherNumber = $this->repository->nextVoucherNumber($fiscalYear);
            $postedAt = gmdate('Y-m-d H:i:s');
            $correlationId = $this->nullableText($voucher['correlation_id'] ?? null, 64);
            $reversalId = $this->repository->insertVoucher(
                $fiscalYear,
                $voucherDate,
                VoucherStatus::POSTED->value,
                trim($description) !== '' ? trim($description) : 'Reversal of voucher ' . $voucherId,
                $totals['debit'],
                $totals['credit'],
                $reversalLines,
                $voucherNumber,
                $voucherId,
                $correlationId,
                $actorUserId,
                $postedAt
            );
            $this->repository->markReversed($voucherId);
            $this->audit->record(
                'accounting.voucher.reversed',
                'journal_voucher',
                (string) $voucherId,
                ['reversal_voucher_id' => $reversalId, 'reversal_voucher_number' => $voucherNumber],
                $correlationId
            );

            return $reversalId;
        });
    }

    /** @return list<array<string, mixed>> */
    public function trialBalance(string $fromDate, string $toDate): array
    {
        $this->assertDate($fromDate);
        $this->assertDate($toDate);
        if ($fromDate > $toDate) {
            throw new AccountingDomainException('The trial balance start date must not follow the end date.');
        }

        return $this->repository->trialBalance($fromDate, $toDate);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function accountPayload(array $data): array
    {
        $normalBalance = strtolower(trim((string) ($data['normal_balance'] ?? '')));
        if (!in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new AccountingDomainException('Normal balance must be debit or credit.');
        }

        return [
            'code' => $this->code($data['code'] ?? null),
            'name' => $this->name($data['name'] ?? null),
            'normal_balance' => $normalBalance,
        ];
    }

    /** @param list<array<string, mixed>> $rawLines @return list<JournalLine> */
    private function linesFromPayload(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $line) {
            $lines[] = new JournalLine(
                $this->positiveId($line['subsidiary_ledger_id'] ?? null, 'subsidiary_ledger_id'),
                isset($line['floating_detail_id']) && $line['floating_detail_id'] !== null
                    ? $this->positiveId($line['floating_detail_id'], 'floating_detail_id')
                    : null,
                $this->nonNegativeAmount($line['debit'] ?? 0, 'debit'),
                $this->nonNegativeAmount($line['credit'] ?? 0, 'credit'),
                trim((string) ($line['description'] ?? ''))
            );
        }

        return $lines;
    }

    /** @param array<string, mixed> $voucher @return list<JournalLine> */
    private function linesFromStoredVoucher(array $voucher): array
    {
        $entries = $voucher['entries'] ?? [];
        if (!is_array($entries)) {
            throw new RuntimeException('Voucher entries could not be loaded.');
        }

        return $this->linesFromPayload($entries);
    }

    /** @param list<JournalLine> $lines */
    private function assertLedgerAssignments(array $lines): void
    {
        $rules = $this->repository->subsidiaryRules(
            array_map(static fn (JournalLine $line): int => $line->subsidiaryLedgerId(), $lines)
        );

        foreach ($lines as $line) {
            $rule = $rules[$line->subsidiaryLedgerId()] ?? null;
            if ($rule === null || !$rule['is_active']) {
                throw new AccountingDomainException('A journal line references a missing or inactive subsidiary ledger.');
            }
            if ($rule['requires_floating_detail'] && $line->floatingDetailId() === null) {
                throw new AccountingDomainException('A floating detail is required for one of the journal lines.');
            }
        }
    }

    private function assertFiscalYear(int $fiscalYear): void
    {
        if ($fiscalYear < 1300 || $fiscalYear > 2500) {
            throw new AccountingDomainException('Fiscal year is outside the supported range.');
        }
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new AccountingDomainException('Date must use the YYYY-MM-DD format.');
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new AccountingDomainException("{$field} must be a positive integer.");
        }

        return $id;
    }

    private function nonNegativeAmount(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            throw new AccountingDomainException("{$field} must be a non-negative integer amount in IRR.");
        }

        return $amount;
    }

    private function code(mixed $value): string
    {
        $code = trim((string) $value);
        if ($code === '' || strlen($code) > 40 || preg_match('/^[0-9A-Za-z._-]+$/', $code) !== 1) {
            throw new AccountingDomainException('Account code contains invalid characters.');
        }

        return $code;
    }

    private function name(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new AccountingDomainException('Account name is required and must be at most 191 characters.');
        }

        return $name;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new AccountingDomainException('A text value exceeds its maximum length.');
        }

        return $text;
    }
}
