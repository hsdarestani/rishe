<?php

declare(strict_types=1);

namespace Rishe\Treasury\Infrastructure;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Sales\Application\SalesService;
use Rishe\Sales\Domain\MobileNormalizer;
use Rishe\Sales\Domain\OrderTotalCalculator;
use Rishe\Sales\Infrastructure\WpAccountingGateway;
use Rishe\Sales\Infrastructure\WpdbSalesRepository;
use Rishe\Sales\Infrastructure\WpInventoryGateway;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Application\SalesPaymentBridge;

final class WpSalesPaymentBridge implements SalesPaymentBridge
{
    private SalesService $service;
    private WpdbSalesRepository $repository;

    public function __construct(?TransactionManager $transactions = null, ?AuditLogger $audit = null)
    {
        $transactions ??= new TransactionManager();
        $audit ??= new AuditLogger();
        $this->repository = new WpdbSalesRepository();
        $inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            $transactions,
            $audit
        );
        $accounting = new AccountingService(
            new WpdbAccountingRepository(),
            $transactions,
            $audit
        );
        $this->service = new SalesService(
            $this->repository,
            new WpInventoryGateway($inventory),
            new WpAccountingGateway($accounting),
            $transactions,
            $audit,
            new MobileNormalizer(),
            new OrderTotalCalculator()
        );
    }

    public function order(int $orderId): ?array
    {
        return $this->repository->order($orderId);
    }

    public function capture(int $orderId, array $payment, int $actorUserId): array
    {
        return $this->service->capturePayment($orderId, $payment, $actorUserId);
    }
}
