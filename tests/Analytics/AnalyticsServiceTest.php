<?php

declare(strict_types=1);

namespace Rishe\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Rishe\Analytics\Application\AnalyticsService;
use Rishe\Analytics\Domain\AnalyticsMath;

final class AnalyticsServiceTest extends TestCase
{
    public function testTargetsEventsProjectionAndDashboardAreDeterministic(): void
    {
        $repository = new FakeAnalyticsRepository();
        $service = new AnalyticsService($repository, new ImmediateTransactions(), new FakeAuditRecorder(), new AnalyticsMath());

        $source = $service->createSource(['code' => 'instagram', 'name' => 'Instagram'], 7);
        $campaign = $service->createCampaign([
            'name' => 'Summer launch',
            'source_id' => $source['id'],
            'starts_at' => '2026-07-01T00:00:00+00:00',
            'ends_at' => '2026-07-31T23:59:59+00:00',
        ], 7);
        $service->attributeOrder(42, ['source_id' => $source['id'], 'campaign_id' => $campaign['id']], 7);
        $service->createTarget([
            'kpi' => 'sales',
            'period_type' => 'month',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'target_value' => 1000000,
        ], 7);
        $service->recordBusinessEvent([
            'event_type' => 'order_paid',
            'occurred_at' => '2026-07-20T12:00:00+00:00',
            'order_id' => 42,
            'product_id' => 8,
            'quantity_scaled' => 10000,
            'revenue_irr' => 750000,
            'cogs_irr' => 500000,
            'order_count' => 1,
        ], 7);

        self::assertSame(['projected' => 1, 'cursor' => 1, 'remaining_hint' => 0], $service->project());
        $dashboard = $service->dashboard('executive', ['from' => '2026-07-01', 'to' => '2026-07-31']);
        self::assertSame(750000, $dashboard['revenue_irr']);
        self::assertSame(250000, $dashboard['gross_profit_irr']);
        self::assertSame(3333, $dashboard['margin_basis_points']);
        self::assertSame(7500, $dashboard['targets'][0]['achievement_basis_points']);
    }

    public function testAuditIngestionIsIgnoredForAnalyticsAuditAndCreatesCanonicalRowsOtherwise(): void
    {
        $repository = new FakeAnalyticsRepository();
        $service = new AnalyticsService($repository, new ImmediateTransactions(), new FakeAuditRecorder(), new AnalyticsMath());

        self::assertSame(['created' => 0, 'ignored' => 1], $service->ingestAuditEvent([
            'event_type' => 'analytics.target.created',
        ]));
        self::assertSame(['created' => 1, 'ignored' => 0], $service->ingestAuditEvent([
            'event_id' => 'audit-1',
            'event_type' => 'sales.payment.captured',
            'aggregate_type' => 'sales_order',
            'aggregate_id' => '99',
        ]));
        self::assertCount(1, $repository->events);
    }
}
