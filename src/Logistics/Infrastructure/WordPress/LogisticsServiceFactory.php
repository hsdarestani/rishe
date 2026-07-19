<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Logistics\Application\LogisticsService;
use Rishe\Logistics\Infrastructure\HmacCarrierWebhookVerifier;
use Rishe\Logistics\Infrastructure\WpCarrierGatewayRegistry;
use Rishe\Logistics\Infrastructure\WpCarrierSecretVault;
use Rishe\Logistics\Infrastructure\WpLogisticsAccountingGateway;
use Rishe\Logistics\Infrastructure\WpLogisticsTreasuryGateway;
use Rishe\Logistics\Infrastructure\WpdbLogisticsRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Infrastructure\WpdbTreasuryRepository;

final class LogisticsServiceFactory
{
    public function create(): LogisticsService
    {
        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $vault = new WpCarrierSecretVault();
        $accounting = new AccountingService(new WpdbAccountingRepository(), $transactions, $audit);

        return new LogisticsService(
            new WpdbLogisticsRepository(),
            new WpCarrierGatewayRegistry($vault),
            $vault,
            new HmacCarrierWebhookVerifier($vault),
            new WpLogisticsTreasuryGateway(new WpdbTreasuryRepository(), $audit),
            new WpLogisticsAccountingGateway($accounting),
            $transactions,
            $audit
        );
    }
}
