<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\CommissionCalculator;
use Rishe\B2B\Domain\ConsignmentLineBalance;
use Rishe\B2B\Domain\CreditExposure;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class B2BService
{
    use B2BAccountOperations;
    use B2BDispatchOperations;
    use B2BReturnOperations;
    use B2BSalesReportOperations;
    use B2BSettlementOperations;
    use B2BValidation;

    public function __construct(
        private readonly B2BRepository $repository,
        private readonly B2BInventoryGateway $inventory,
        private readonly B2BAccountingGateway $accounting,
        private readonly B2BTreasuryGateway $treasury,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit,
        private readonly CommissionCalculator $commissions,
        private readonly CreditExposure $credit,
        private readonly ConsignmentLineBalance $lineBalance
    ) {
    }
}
