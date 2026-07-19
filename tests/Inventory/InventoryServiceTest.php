<?php

declare(strict_types=1);

namespace Rishe\Tests\Inventory;

use PHPUnit\Framework\TestCase;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Tests\Inventory\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Inventory\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Inventory\Fakes\InMemoryInventoryRepository;

final class InventoryServiceTest extends TestCase
{
    public function testReservationUsesScaledQuantityAndAuditTransaction(): void
    {
        $repository = new InMemoryInventoryRepository();
        $transactions = new ImmediateTransactionRunner();
        $audit = new InMemoryAuditRecorder();
        $service = new InventoryService($repository, $transactions, $audit);

        $id = $service->reserveStock([
            'product_id' => 10,
            'warehouse_id' => 2,
            'quantity' => '2.5',
            'reference_type' => 'order',
            'reference_id' => '1001',
            'correlation_id' => 'order-1',
        ], 7);

        self::assertSame(31, $id);
        self::assertSame(25000, $repository->lastPayload['quantity_scaled']);
        self::assertSame('fifo', $repository->lastPayload['inventory_method']);
        self::assertSame(1, $transactions->runs);
        self::assertSame('inventory.stock.reserved', $audit->events[0]['event_type']);
    }

    public function testReservationCommitReturnsCogs(): void
    {
        $service = new InventoryService(
            new InMemoryInventoryRepository(),
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );

        self::assertSame(
            ['quantity_scaled' => 25000, 'cogs_irr' => 450000],
            $service->commitReservation(31, 7)
        );
    }

    public function testTransferRejectsSameWarehouse(): void
    {
        $service = new InventoryService(
            new InMemoryInventoryRepository(),
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );

        $this->expectException(\Rishe\Inventory\Domain\Exception\InventoryDomainException::class);

        $service->transferStock([
            'product_id' => 10,
            'from_warehouse_id' => 2,
            'to_warehouse_id' => 2,
            'quantity' => '1',
        ], 7);
    }
}
