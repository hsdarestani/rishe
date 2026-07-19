<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

use Rishe\Sales\Domain\MobileNormalizer;
use Rishe\Sales\Domain\OrderTotalCalculator;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class SalesService
{
    use SalesCatalogOperations;
    use SalesOrderOperations;
    use SalesValidation;

    /** @var list<string> */
    private const CHANNELS = [
        'woocommerce', 'website', 'telegram', 'instagram', 'bale', 'pos', 'b2b', 'event', 'manual', 'other',
    ];

    public function __construct(
        private readonly SalesRepository $repository,
        private readonly InventoryGateway $inventory,
        private readonly AccountingGateway $accounting,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit,
        private readonly MobileNormalizer $mobiles,
        private readonly OrderTotalCalculator $totals
    ) {
    }
}
