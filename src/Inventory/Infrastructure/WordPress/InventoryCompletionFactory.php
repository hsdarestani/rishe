<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryCompletionService;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\BatchSelectionPolicy;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryCompletionRepository;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Shared\Audit\AuditLogger;

final class InventoryCompletionFactory
{
    public function completion(): InventoryCompletionService
    {
        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $policy = new BatchSelectionPolicy();
        $inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator(), $policy),
            $transactions,
            $audit
        );

        return new InventoryCompletionService(
            new WpdbInventoryCompletionRepository(),
            $inventory,
            $transactions,
            $audit
        );
    }
}
