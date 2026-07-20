<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure\WordPress;

use Rishe\Analytics\Application\AnalyticsService;
use Rishe\Analytics\Domain\AnalyticsMath;
use Rishe\Analytics\Infrastructure\WpdbAnalyticsRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;

final class AnalyticsServiceFactory
{
    public function service(): AnalyticsService
    {
        return new AnalyticsService(
            new WpdbAnalyticsRepository(),
            new TransactionManager(),
            new AuditLogger(),
            new AnalyticsMath()
        );
    }
}
