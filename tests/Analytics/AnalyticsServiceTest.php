<?php

declare(strict_types=1);

namespace Rishe\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Rishe\Analytics\Application\AnalyticsRepository;
use Rishe\Analytics\Application\AnalyticsService;
use Rishe\Analytics\Domain\AnalyticsMath;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

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

final class ImmediateTransactions implements TransactionRunner
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}

final class FakeAuditRecorder implements AuditRecorder
{
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string {
        return $eventType . ':' . $aggregateType . ':' . $aggregateId;
    }
}

final class FakeAnalyticsRepository implements AnalyticsRepository
{
    /** @var list<array<string, mixed>> */
    public array $events = [];
    /** @var list<array<string, mixed>> */
    private array $targets = [];
    private int $cursor = 0;
    private int $id = 0;
    private int $revenue = 0;
    private int $cogs = 0;
    private int $orders = 0;

    public function createSource(array $data): array
    {
        return ['id' => ++$this->id] + $data;
    }

    public function sources(bool $activeOnly = false): array
    {
        return [];
    }

    public function createCampaign(array $data): array
    {
        return ['id' => ++$this->id] + $data;
    }

    public function campaigns(array $filters = []): array
    {
        return [];
    }

    public function attributeOrder(int $orderId, array $data): array
    {
        return ['id' => ++$this->id, 'order_id' => $orderId, 'idempotent' => false] + $data;
    }

    public function recordPrice(array $data): array
    {
        return ['id' => ++$this->id] + $data;
    }

    public function priceHistory(array $filters = []): array
    {
        return [];
    }

    public function createTarget(array $data): array
    {
        $row = ['id' => ++$this->id, 'actual_value' => 750000] + $data;
        $this->targets[] = $row;
        return $row;
    }

    public function targets(array $filters = []): array
    {
        return $this->targets;
    }

    public function appendBusinessEvent(array $data): array
    {
        $row = ['id' => count($this->events) + 1, 'event_key' => $data['event_key'] ?? 'event-' . (count($this->events) + 1)] + $data;
        $this->events[] = $row;
        return $row;
    }

    public function businessRowsFromAudit(array $auditEvent): array
    {
        return [[
            'event_key' => 'event-audit',
            'event_type' => 'order_paid',
            'occurred_at' => '2026-07-20 12:00:00',
            'revenue_irr' => 0,
            'cogs_irr' => 0,
            'gross_profit_irr' => 0,
            'quantity_scaled' => 0,
            'discount_irr' => 0,
            'order_count' => 1,
            'source_audit_event_id' => $auditEvent['event_id'] ?? null,
            'event_sequence' => 0,
            'payload' => [],
        ]];
    }

    public function events(array $filters = []): array
    {
        return $this->events;
    }

    public function eventsAfterCursor(int $limit): array
    {
        return array_slice(array_values(array_filter($this->events, fn (array $event): bool => $event['id'] > $this->cursor)), 0, $limit);
    }

    public function projectEvent(array $event): void
    {
        $this->revenue += (int) ($event['revenue_irr'] ?? 0);
        $this->cogs += (int) ($event['cogs_irr'] ?? 0);
        $this->orders += (int) ($event['order_count'] ?? 0);
    }

    public function projectionCursor(): int
    {
        return $this->cursor;
    }

    public function advanceProjectionCursor(int $eventId): void
    {
        $this->cursor = max($this->cursor, $eventId);
    }

    public function captureInventorySnapshot(string $date): array
    {
        return ['snapshots' => 1];
    }

    public function executiveDashboard(array $filters): array
    {
        return [
            'revenue_irr' => $this->revenue,
            'cogs_irr' => $this->cogs,
            'gross_profit_irr' => $this->revenue - $this->cogs,
            'discount_irr' => 0,
            'order_count' => $this->orders,
            'sales_qty_scaled' => 10000,
            'average_order_value_irr' => $this->orders > 0 ? intdiv($this->revenue, $this->orders) : 0,
            'today_revenue_irr' => $this->revenue,
            'week_revenue_irr' => $this->revenue,
            'month_revenue_irr' => $this->revenue,
            'open_alerts' => 0,
        ];
    }

    public function salesDashboard(array $filters): array
    {
        return $this->executiveDashboard($filters);
    }

    public function inventoryDashboard(array $filters): array
    {
        return [];
    }

    public function financeDashboard(array $filters): array
    {
        return $this->executiveDashboard($filters);
    }

    public function customerDashboard(array $filters): array
    {
        return [];
    }

    public function alertCandidates(string $now): array
    {
        return [];
    }

    public function upsertAlert(array $candidate): array
    {
        return ['id' => 1, 'created' => true] + $candidate;
    }

    public function alerts(array $filters = []): array
    {
        return [];
    }

    public function updateAlert(int $alertId, string $status, int $actorUserId): array
    {
        return ['id' => $alertId, 'status' => $status];
    }
}
