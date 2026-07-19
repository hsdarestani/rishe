<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class TreasuryService
{
    use TreasuryCatalogOperations;
    use TreasuryPaymentOperations;
    use TreasuryReconciliationOperations;
    use TreasuryValidation;

    /** @var list<string> */
    private const ACCOUNT_TYPES = ['bank', 'cash', 'pos', 'gateway'];

    public function __construct(
        private readonly TreasuryRepository $repository,
        private readonly PaymentLinkGateway $gateway,
        private readonly SalesPaymentBridge $sales,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit
    ) {
    }
}
