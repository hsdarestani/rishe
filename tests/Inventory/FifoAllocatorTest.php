<?php

declare(strict_types=1);

namespace Rishe\Tests\Inventory;

use PHPUnit\Framework\TestCase;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\FifoAllocator;

final class FifoAllocatorTest extends TestCase
{
    public function testAllocationConsumesOldestBatchesInOrder(): void
    {
        $allocations = (new FifoAllocator())->allocate([
            ['id' => 1, 'available_scaled' => 50000, 'unit_cost_irr' => 100000, 'batch_code' => 'B1'],
            ['id' => 2, 'available_scaled' => 90000, 'unit_cost_irr' => 120000, 'batch_code' => 'B2'],
        ], 110000);

        self::assertSame([
            ['batch_id' => 1, 'quantity_scaled' => 50000, 'unit_cost_irr' => 100000, 'batch_code' => 'B1'],
            ['batch_id' => 2, 'quantity_scaled' => 60000, 'unit_cost_irr' => 120000, 'batch_code' => 'B2'],
        ], $allocations);
    }

    public function testInsufficientStockIsRejected(): void
    {
        $this->expectException(InventoryDomainException::class);

        (new FifoAllocator())->allocate([
            ['id' => 1, 'available_scaled' => 10000, 'unit_cost_irr' => 1, 'batch_code' => 'B1'],
        ], 20000);
    }
}
