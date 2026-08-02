<?php

declare(strict_types=1);

namespace Rishe\Tests\Inventory;

use PHPUnit\Framework\TestCase;
use Rishe\Inventory\Domain\BatchSelectionPolicy;

final class BatchSelectionPolicyTest extends TestCase
{
    public function testFefoUsesExpiryBeforeReceiptAndKeepsUndatedBatchesLast(): void
    {
        $policy = new BatchSelectionPolicy();
        $rows = $policy->sort([
            ['id' => 1, 'expiry_date' => null, 'received_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'expiry_date' => '2026-09-01', 'received_at' => '2026-03-01 00:00:00'],
            ['id' => 3, 'expiry_date' => '2026-08-01', 'received_at' => '2026-04-01 00:00:00'],
            ['id' => 4, 'expiry_date' => '2026-08-01', 'received_at' => '2026-02-01 00:00:00'],
        ], 'fefo');

        self::assertSame([4, 3, 2, 1], array_column($rows, 'id'));
    }

    public function testFifoAndLifoRemainAvailableForNonPerishableProducts(): void
    {
        $policy = new BatchSelectionPolicy();
        $rows = [
            ['id' => 1, 'expiry_date' => null, 'received_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'expiry_date' => null, 'received_at' => '2026-02-01 00:00:00'],
        ];

        self::assertSame([1, 2], array_column($policy->sort($rows, 'fifo'), 'id'));
        self::assertSame([2, 1], array_column($policy->sort($rows, 'lifo'), 'id'));
    }
}
