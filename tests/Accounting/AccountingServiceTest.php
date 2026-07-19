<?php

declare(strict_types=1);

namespace Rishe\Tests\Accounting;

use PHPUnit\Framework\TestCase;
use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Tests\Support\ImmediateTransactionRunner;
use Rishe\Tests\Support\InMemoryAccountingRepository;
use Rishe\Tests\Support\InMemoryAuditRecorder;

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
