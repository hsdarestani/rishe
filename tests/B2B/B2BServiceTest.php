<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B;

use PHPUnit\Framework\TestCase;
use Rishe\B2B\Application\B2BService;
use Rishe\B2B\Domain\CommissionCalculator;
use Rishe\B2B\Domain\ConsignmentLineBalance;
use Rishe\B2B\Domain\CreditExposure;
use Rishe\Tests\B2B\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\B2B\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\B2B\Fakes\InMemoryB2BAccountingGateway;
use Rishe\Tests\B2B\Fakes\InMemoryB2BInventoryGateway;
use Rishe\Tests\B2B\Fakes\InMemoryB2BRepository;
use Rishe\Tests\B2B\Fakes\InMemoryB2BTreasuryGateway;

final class B2BServiceTest extends TestCase
{
    private InMemoryB2BRepository $repository;
    private InMemoryB2BInventoryGateway $inventory;
    private InMemoryB2BAccountingGateway $accounting;
    private InMemoryB2BTreasuryGateway $treasury;
    private B2BService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryB2BRepository();
        $this->inventory = new InMemoryB2BInventoryGateway();
        $this->accounting = new InMemoryB2BAccountingGateway();
        $this->treasury = new InMemoryB2BTreasuryGateway();
        $this->service = new B2BService(
            $this->repository,
            $this->inventory,
            $this->accounting,
            $this->treasury,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder(),
            new CommissionCalculator(),
            new CreditExposure(),
            new ConsignmentLineBalance()
        );
    }

    public function testDispatchSalesReturnAndSettlementFlow(): void
    {
        $account = $this->createAccount();
        $dispatch = $this->service->createDispatch([
            'account_id' => $account['id'],
            'source_warehouse_id' => 1,
            'fiscal_year' => 1405,
            'dispatched_at' => '2026-07-19 12:00:00',
            'idempotency_key' => 'dispatch-1',
            'lines' => [[
                'product_id' => 10,
                'quantity' => '10',
            ]],
        ], 1);
        self::assertSame('active', $dispatch['status']);
        self::assertCount(1, $this->inventory->transfers);

        $report = $this->service->postSalesReport([
            'account_id' => $account['id'],
            'fiscal_year' => 1405,
            'reported_at' => '2026-07-20 10:00:00',
            'idempotency_key' => 'report-1',
            'lines' => [[
                'product_id' => 10,
                'quantity' => '4',
                'unit_price_irr' => 100000,
            ]],
        ], 1);
        self::assertSame(400000, $report['gross_irr']);
        self::assertSame(40000, $report['commission_irr']);
        self::assertSame(360000, $report['receivable_irr']);
        self::assertSame(12000, $report['cogs_irr']);
        self::assertSame(360000, $this->repository->accounts[1]['current_receivable_irr']);

        $return = $this->service->returnConsignment((int) $dispatch['id'], [
            'returned_at' => '2026-07-21 10:00:00',
            'idempotency_key' => 'return-1',
            'lines' => [[
                'dispatch_line_id' => 1,
                'quantity' => '6',
            ]],
        ], 1);
        self::assertSame('posted', $return['status']);
        self::assertSame('closed', $this->repository->dispatches[1]['status']);

        $settlement = $this->service->settleAccount(1, 80, 200000, 1);
        self::assertSame(160000, $settlement['outstanding_irr']);
        self::assertSame(160000, $this->repository->accounts[1]['current_receivable_irr']);
        self::assertCount(1, $this->accounting->reports);
        self::assertCount(1, $this->accounting->settlements);
        self::assertCount(2, $this->repository->statement(1));
    }

    /** @return array{id: int, created: bool} */
    private function createAccount(): array
    {
        return $this->service->upsertAccount([
            'customer_id' => 1,
            'code' => 'AGENT-1',
            'name' => 'Agent One',
            'account_type' => 'consignment',
            'consignment_warehouse_id' => 2,
            'credit_limit_irr' => 1000000,
            'commission_rate_bps' => 1000,
            'settlement_terms_days' => 7,
        ], 1);
    }
}
