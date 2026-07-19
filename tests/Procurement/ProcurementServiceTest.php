<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement;

use PHPUnit\Framework\TestCase;
use Rishe\Procurement\Application\ProcurementService;
use Rishe\Procurement\Domain\LandedCostAllocator;
use Rishe\Procurement\Domain\ReceiptProrator;
use Rishe\Tests\Procurement\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Procurement\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Procurement\Fakes\InMemoryInventoryReceiptGateway;
use Rishe\Tests\Procurement\Fakes\InMemoryProcurementAccountingGateway;
use Rishe\Tests\Procurement\Fakes\InMemoryProcurementRepository;
use Rishe\Tests\Procurement\Fakes\InMemoryProcurementTreasuryGateway;

final class ProcurementServiceTest extends TestCase
{
    private InMemoryProcurementRepository $repository;
    private InMemoryInventoryReceiptGateway $inventory;
    private InMemoryProcurementAccountingGateway $accounting;
    private InMemoryProcurementTreasuryGateway $treasury;
    private ProcurementService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProcurementRepository();
        $this->inventory = new InMemoryInventoryReceiptGateway();
        $this->accounting = new InMemoryProcurementAccountingGateway();
        $this->treasury = new InMemoryProcurementTreasuryGateway();
        $this->service = new ProcurementService(
            $this->repository,
            $this->inventory,
            $this->accounting,
            $this->treasury,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder(),
            new LandedCostAllocator(),
            new ReceiptProrator()
        );
    }

    public function testReceiptCapitalizesLandedCostAndCreatesSupplierLiability(): void
    {
        $order = $this->createApprovedOrder();
        $receipt = $this->service->receivePurchaseOrder((int) $order['id'], [
            'received_at' => '2026-07-19 12:00:00',
            'idempotency_key' => 'receipt-1',
            'lines' => [[
                'purchase_order_line_id' => 1,
                'quantity' => '10',
                'batch_code' => 'RICE-20260719',
                'expiry_date' => '2027-07-19',
            ]],
            'landed_costs' => [[
                'cost_type' => 'freight',
                'amount_irr' => 11000,
                'allocation_basis' => 'value',
            ]],
        ], 1);

        self::assertSame('posted', $receipt['status']);
        self::assertSame(110000, $receipt['liability_irr']);
        self::assertSame(10100, $this->inventory->receipts[0]['unit_cost_irr']);
        self::assertSame(110000, $this->repository->orders[1]['received_liability_irr']);
        self::assertCount(1, $this->accounting->receipts);
    }

    public function testDebitTreasuryTransactionPaysOutstandingLiability(): void
    {
        $order = $this->createApprovedOrder();
        $this->service->receivePurchaseOrder((int) $order['id'], [
            'received_at' => '2026-07-19 12:00:00',
            'idempotency_key' => 'receipt-1',
            'lines' => [[
                'purchase_order_line_id' => 1,
                'quantity' => '10',
                'batch_code' => 'RICE-20260719',
            ]],
            'landed_costs' => [[
                'cost_type' => 'freight',
                'amount_irr' => 11000,
            ]],
        ], 1);

        $payment = $this->service->registerSupplierPayment(1, 70, 100000, 1);

        self::assertSame(10000, $payment['outstanding_irr']);
        self::assertCount(1, $this->treasury->matches);
        self::assertCount(1, $this->accounting->payments);
        self::assertSame(100000, $this->repository->orders[1]['paid_irr']);
    }

    /** @return array<string, mixed> */
    private function createApprovedOrder(): array
    {
        $order = $this->service->createPurchaseOrder([
            'supplier_id' => 1,
            'warehouse_id' => 1,
            'fiscal_year' => 1405,
            'idempotency_key' => 'po-1',
            'lines' => [[
                'product_id' => 10,
                'quantity' => '10',
                'unit_price_irr' => 10000,
                'discount_irr' => 10000,
                'tax_irr' => 9000,
            ]],
        ], 1);

        return $this->service->approvePurchaseOrder((int) $order['id'], 1);
    }
}
