<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Logistics\Infrastructure\WordPress\LogisticsServiceFactory;
use Rishe\Operations\Application\ConfigurationManager;
use Rishe\Operations\Application\DiagnosticsService;
use Rishe\Operations\Application\OperationsService;
use Rishe\Operations\Domain\BackoffPolicy;
use Rishe\Operations\Domain\ConfigurationPackage;
use Rishe\Operations\Domain\DiagnosticSummary;
use Rishe\Operations\Infrastructure\Handlers\LogisticsTrackingRefreshJobHandler;
use Rishe\Operations\Infrastructure\Handlers\SystemNoopJobHandler;
use Rishe\Operations\Infrastructure\Handlers\TaxInquiryJobHandler;
use Rishe\Operations\Infrastructure\Handlers\TaxSubmitJobHandler;
use Rishe\Operations\Infrastructure\StaticJobHandlerRegistry;
use Rishe\Operations\Infrastructure\WpConfigurationStore;
use Rishe\Operations\Infrastructure\WpJobScheduler;
use Rishe\Operations\Infrastructure\WpSystemProbe;
use Rishe\Operations\Infrastructure\WpdbOperationsRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Tax\Infrastructure\WordPress\TaxServiceFactory;

final class OperationsServiceFactory
{
    public function operations(): OperationsService
    {
        $tax = (new TaxServiceFactory())->create();
        $logistics = (new LogisticsServiceFactory())->create();
        $handlers = new StaticJobHandlerRegistry([
            new SystemNoopJobHandler(),
            new TaxSubmitJobHandler($tax),
            new TaxInquiryJobHandler($tax),
            new LogisticsTrackingRefreshJobHandler($logistics),
        ]);

        return new OperationsService(
            new WpdbOperationsRepository(),
            $handlers,
            new WpJobScheduler(),
            new TransactionManager(),
            new AuditLogger(),
            new BackoffPolicy()
        );
    }

    public function diagnostics(): DiagnosticsService
    {
        return new DiagnosticsService(
            new WpSystemProbe(),
            new WpdbOperationsRepository(),
            new DiagnosticSummary()
        );
    }

    public function configuration(): ConfigurationManager
    {
        return new ConfigurationManager(
            new WpConfigurationStore(),
            new ConfigurationPackage(),
            new AuditLogger()
        );
    }
}
