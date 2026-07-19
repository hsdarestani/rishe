<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class LogisticsService
{
    use LogisticsCarrierOperations;
    use LogisticsShipmentOperations;
    use LogisticsTrackingOperations;
    use LogisticsCostOperations;
    use LogisticsValidation;

    public function __construct(
        private readonly LogisticsRepository $repository,
        private readonly CarrierGatewayRegistry $gateways,
        private readonly CarrierSecretVault $vault,
        private readonly CarrierWebhookVerifier $webhooks,
        private readonly LogisticsTreasuryGateway $treasury,
        private readonly LogisticsAccountingGateway $accounting,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit
    ) {
    }
}
