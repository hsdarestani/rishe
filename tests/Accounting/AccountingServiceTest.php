<?php

declare(strict_types=1);

namespace Rishe\Tests\Accounting;

use PHPUnit\Framework\TestCase;
use Rishe\Accounting\Application\AccountingRepository;
use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Accounting\Domain\Journal\JournalLine;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class AccountingServiceTest extends TestCase
{
    public function testDraftVoucherIsPersistedAndAuditedInsideTransaction(): void
    {
        $repository = new InMemoryAccountingRepository();
        $repository->rules = [
            10 => ['requires_floating_detail' => false, 'is_active' => true],
            20 => ['requires_floating_detail' => false, 'is_active' => true],
        ];
        $transactions = new ImmediateTransactionRunner();
        $audit = new InMemoryAuditRecorder();
        $service = new AccountingService($repository, $transactions, $audit);

        $id = $service->createDraftVoucher(1405, '2026-07-19', 'Cash sale', [
            ['subsidiary_ledger_id' => 10, 'debit' => 250000, 'credit' => 0],
            ['subsidiary_ledger_id' => 20, 'debit' => 0, 'credit' => 250000],
        ], 'order-1');

        self::assertSame(77, $id);
        self::assertSame(1, $transactions->runs);
        self::assertSame('draft', $repository->insertedVoucher['status']);
        self::assertSame(250000, $repository->insertedVoucher['total_debit']);
        self::assertSame(250000, $repository->insertedVoucher['total_credit']);
        self::assertSame('accounting.voucher.created', $audit->events[0]['event_type']);
    }

    public function testPostingRevalidatesVoucherAndAssignsFiscalNumber(): void
    {
        $repository = new InMemoryAccountingRepository();
        $repository->rules = [
            10 => ['requires_floating_detail' => false, 'is_active' => true],
            20 => ['requires_floating_detail' => false, 'is_active' => true],
        ];
        $repository->voucher = [
            'id' => 50,
            'fiscal_year' => 1405,
            'status' => 'draft',
            'total_debit' => 900000,
            'total_credit' => 900000,
            'correlation_id' => 'order-2',
            'entries' => [
                ['subsidiary_ledger_id' => 10, 'floating_detail_id' => null, 'debit' => 900000, 'credit' => 0],
                ['subsidiary_ledger_id' => 20, 'floating_detail_id' => null, 'debit' => 0, 'credit' => 900000],
            ],
        ];
        $repository->nextNumber = 12;
        $service = new AccountingService(
            $repository,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );

        $number = $service->postVoucher(50, 7);

        self::assertSame(12, $number);
        self::assertSame(['voucher_id' => 50, 'voucher_number' => 12, 'actor_user_id' => 7], $repository->posted);
    }

    public function testRequiredFloatingDetailIsRejected(): void
    {
        $repository = new InMemoryAccountingRepository();
        $repository->rules = [
            10 => ['requires_floating_detail' => true, 'is_active' => true],
            20 => ['requires_floating_detail' => false, 'is_active' => true],
        ];
        $service = new AccountingService(
            $repository,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );

        $this->expectException(AccountingDomainException::class);

        $service->createDraftVoucher(1405, '2026-07-19', 'Missing customer detail', [
            ['subsidiary_ledger_id' => 10, 'debit' => 1000, 'credit' => 0],
            ['subsidiary_ledger_id' => 20, 'debit' => 0, 'credit' => 1000],
        ]);
    }
}

final class ImmediateTransactionRunner implements TransactionRunner
{
    public int $runs = 0;

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        return $operation();
    }
}

final class InMemoryAuditRecorder implements AuditRecorder
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string {
        $this->events[] = [
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'correlation_id' => $correlationId,
        ];

        return 'event-' . count($this->events);
    }
}

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
