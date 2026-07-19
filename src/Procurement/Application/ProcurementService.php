<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use Rishe\Procurement\Domain\LandedCostAllocator;
use Rishe\Procurement\Domain\ReceiptProrator;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class ProcurementService
{
    use ProcurementCatalogOperations;
    use ProcurementOrderOperations;
    use ProcurementReceiptOperations;
    use ProcurementPaymentOperations;
    use ProcurementValidation;

    public function __construct(
        private readonly ProcurementRepository $repository,
        private readonly InventoryReceiptGateway $inventory,
        private readonly ProcurementAccountingGateway $accounting,
        private readonly ProcurementTreasuryGateway $treasury,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit,
        private readonly LandedCostAllocator $landedCosts,
        private readonly ReceiptProrator $prorator
    ) {
    }
}
