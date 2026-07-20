<?php

declare(strict_types=1);

namespace Rishe\Analytics\Application;

use DateTimeImmutable;
use Rishe\Analytics\Domain\AnalyticsMath;
use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class AnalyticsService
{
    /** @var list<string> */
    private const KPIS = ['sales', 'gross_profit', 'order_count'];

    /** @var list<string> */
    private const PERIODS = ['day', 'week', 'month'];

    /** @var list<string> */
    private const ALERT_STATUSES = ['open', 'acknowledged', 'resolved'];

    public function __construct(
        private readonly AnalyticsRepository $repository,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit,
        private readonly AnalyticsMath $math
    ) {
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createSource(array $data, int $actorUserId): array
    {
        $code = $this->code((string) ($data['code'] ?? ''), 'Source code');
        $name = $this->requiredText((string) ($data['name'] ?? ''), 'Source name', 191);
        $channel = $this->nullableCode($data['channel'] ?? null);

        return $this->transactions->run(function () use ($data, $actorUserId, $code, $name, $channel): array {
            $source = $this->repository->createSource([
                'code' => $code,
                'name' => $name,
                'channel' => $channel,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'created_by' => max(1, $actorUserId),
            ]);
            $this->audit->record('analytics.source.created', 'analytics_source', (string) $source['id'], [
                'code' => $code,
                'name' => $name,
            ]);

            return $source;
        });
    }

    /** @return list<array<string, mixed>> */
    public function sources(bool $activeOnly = false): array
    {
        return $this->repository->sources($activeOnly);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createCampaign(array $data, int $actorUserId): array
    {
        $startsAt = $this->dateTime((string) ($data['starts_at'] ?? ''), 'Campaign start');
        $endsAt = $this->dateTime((string) ($data['ends_at'] ?? ''), 'Campaign end');
        if ($endsAt < $startsAt) {
            throw new AnalyticsDomainException('Campaign end must not be before its start.');
        }

        $payload = [
            'campaign_key' => $this->uuid((string) ($data['campaign_key'] ?? '')),
            'name' => $this->requiredText((string) ($data['name'] ?? ''), 'Campaign name', 191),
            'channel' => $this->nullableCode($data['channel'] ?? null),
            'source_id' => $this->nullablePositiveInt($data['source_id'] ?? null),
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'objective' => $this->optionalText($data['objective'] ?? null, 500),
            'target_irr' => $this->nonNegativeInt($data['target_irr'] ?? 0, 'Campaign target'),
            'budget_irr' => $this->nonNegativeInt($data['budget_irr'] ?? 0, 'Campaign budget'),
            'status' => $this->enum((string) ($data['status'] ?? 'planned'), ['planned', 'active', 'completed', 'cancelled'], 'Campaign status'),
            'created_by' => max(1, $actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $campaign = $this->repository->createCampaign($payload);
            $this->audit->record('analytics.campaign.created', 'analytics_campaign', (string) $campaign['id'], [
                'campaign_key' => $campaign['campaign_key'],
                'name' => $campaign['name'],
            ]);

            return $campaign;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function campaigns(array $filters = []): array
    {
        return $this->repository->campaigns($filters);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function attributeOrder(int $orderId, array $data, int $actorUserId): array
    {
        if ($orderId <= 0) {
            throw new AnalyticsDomainException('Order id must be positive.');
        }
        $payload = [
            'source_id' => $this->nullablePositiveInt($data['source_id'] ?? null),
            'campaign_id' => $this->nullablePositiveInt($data['campaign_id'] ?? null),
            'branch_id' => $this->nullablePositiveInt($data['branch_id'] ?? null),
            'salesperson_user_id' => $this->nullablePositiveInt($data['salesperson_user_id'] ?? null),
            'province' => $this->optionalText($data['province'] ?? null, 100),
            'city' => $this->optionalText($data['city'] ?? null, 100),
            'attributed_by' => max(1, $actorUserId),
        ];
        if ($payload['source_id'] === null && $payload['campaign_id'] === null) {
            throw new AnalyticsDomainException('At least a source or campaign is required.');
        }

        return $this->transactions->run(function () use ($orderId, $payload): array {
            $attribution = $this->repository->attributeOrder($orderId, $payload);
            $this->audit->record('analytics.order.attributed', 'sales_order', (string) $orderId, $attribution);

            return $attribution;
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function recordPrice(array $data, int $actorUserId): array
    {
        $payload = [
            'product_id' => $this->positiveInt($data['product_id'] ?? null, 'Product id'),
            'channel' => $this->code((string) ($data['channel'] ?? 'all'), 'Channel'),
            'purchase_price_irr' => $this->nonNegativeInt($data['purchase_price_irr'] ?? 0, 'Purchase price'),
            'cogs_irr' => $this->nonNegativeInt($data['cogs_irr'] ?? 0, 'COGS'),
            'selling_price_irr' => $this->nonNegativeInt($data['selling_price_irr'] ?? 0, 'Selling price'),
            'effective_from' => $this->dateTime((string) ($data['effective_from'] ?? gmdate('c')), 'Effective from')->format('Y-m-d H:i:s'),
            'reason' => $this->optionalText($data['reason'] ?? null, 500),
            'actor_user_id' => max(1, $actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $price = $this->repository->recordPrice($payload);
            $this->audit->record('analytics.price.changed', 'product', (string) $payload['product_id'], $price);

            return $price;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function priceHistory(array $filters = []): array
    {
        return $this->repository->priceHistory($filters);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createTarget(array $data, int $actorUserId): array
    {
        $periodType = $this->enum((string) ($data['period_type'] ?? ''), self::PERIODS, 'Target period');
        $startsOn = $this->date((string) ($data['starts_on'] ?? ''), 'Target start');
        $endsOn = $this->date((string) ($data['ends_on'] ?? ''), 'Target end');
        if ($endsOn < $startsOn) {
            throw new AnalyticsDomainException('Target end must not be before target start.');
        }
        $payload = [
            'target_key' => $this->uuid((string) ($data['target_key'] ?? '')),
            'kpi' => $this->enum((string) ($data['kpi'] ?? ''), self::KPIS, 'Target KPI'),
            'period_type' => $periodType,
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => $endsOn->format('Y-m-d'),
            'product_line' => $this->optionalText($data['product_line'] ?? null, 100),
            'sales_channel' => $this->nullableCode($data['sales_channel'] ?? null),
            'province' => $this->optionalText($data['province'] ?? null, 100),
            'city' => $this->optionalText($data['city'] ?? null, 100),
            'target_value' => $this->positiveInt($data['target_value'] ?? null, 'Target value'),
            'created_by' => max(1, $actorUserId),
        ];
        $payload['dimension_hash'] = hash('sha256', implode('|', [
            $payload['kpi'], $payload['period_type'], $payload['starts_on'], $payload['ends_on'],
            $payload['product_line'] ?? '', $payload['sales_channel'] ?? '', $payload['province'] ?? '', $payload['city'] ?? '',
        ]));

        return $this->transactions->run(function () use ($payload): array {
            $target = $this->repository->createTarget($payload);
            $this->audit->record('analytics.target.created', 'analytics_target', (string) $target['id'], $target);

            return $target;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function targets(array $filters = []): array
    {
        $rows = $this->repository->targets($filters);
        foreach ($rows as &$row) {
            $target = (int) ($row['target_value'] ?? 0);
            $actual = (int) ($row['actual_value'] ?? 0);
            $row['achievement_basis_points'] = $target > 0 ? $this->math->achievementBasisPoints($actual, $target) : 0;
            $row['variance'] = $actual - $target;
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function recordBusinessEvent(array $data, int $actorUserId): array
    {
        $revenue = $this->nonNegativeInt($data['revenue_irr'] ?? 0, 'Revenue');
        $cogs = $this->nonNegativeInt($data['cogs_irr'] ?? 0, 'COGS');
        $payload = [
            'event_key' => $this->uuid((string) ($data['event_key'] ?? '')),
            'event_group_key' => $this->nullableUuid($data['event_group_key'] ?? null),
            'source_audit_event_id' => null,
            'event_sequence' => 0,
            'event_type' => $this->code((string) ($data['event_type'] ?? ''), 'Event type'),
            'occurred_at' => $this->dateTime((string) ($data['occurred_at'] ?? gmdate('c')), 'Event time')->format('Y-m-d H:i:s'),
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'branch_id' => $this->nullablePositiveInt($data['branch_id'] ?? null),
            'sales_channel' => $this->nullableCode($data['sales_channel'] ?? null),
            'source_code' => $this->nullableCode($data['source_code'] ?? null),
            'campaign_id' => $this->nullablePositiveInt($data['campaign_id'] ?? null),
            'customer_id' => $this->nullablePositiveInt($data['customer_id'] ?? null),
            'order_id' => $this->nullablePositiveInt($data['order_id'] ?? null),
            'product_id' => $this->nullablePositiveInt($data['product_id'] ?? null),
            'product_line' => $this->optionalText($data['product_line'] ?? null, 100),
            'quantity_scaled' => (int) ($data['quantity_scaled'] ?? 0),
            'revenue_irr' => $revenue,
            'cogs_irr' => $cogs,
            'gross_profit_irr' => $this->math->grossProfit($revenue, $cogs),
            'discount_irr' => $this->nonNegativeInt($data['discount_irr'] ?? 0, 'Discount'),
            'province' => $this->optionalText($data['province'] ?? null, 100),
            'city' => $this->optionalText($data['city'] ?? null, 100),
            'aggregate_type' => $this->nullableCode($data['aggregate_type'] ?? null),
            'aggregate_id' => $this->optionalText($data['aggregate_id'] ?? null, 191),
            'correlation_id' => $this->optionalText($data['correlation_id'] ?? null, 64),
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
            'order_count' => (int) ($data['order_count'] ?? 0),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $event = $this->repository->appendBusinessEvent($payload);
            $this->audit->record('analytics.business_event.recorded', 'business_event', (string) $event['id'], [
                'event_key' => $event['event_key'],
                'event_type' => $event['event_type'],
            ], $payload['correlation_id']);

            return $event;
        });
    }

    /** @param array<string, mixed> $auditEvent @return array<string, int> */
    public function ingestAuditEvent(array $auditEvent): array
    {
        $auditType = strtolower((string) ($auditEvent['event_type'] ?? ''));
        if ($auditType === '' || str_starts_with($auditType, 'analytics.')) {
            return ['created' => 0, 'ignored' => 1];
        }
        $rows = $this->repository->businessRowsFromAudit($auditEvent);
        if ($rows === []) {
            return ['created' => 0, 'ignored' => 1];
        }

        return $this->transactions->run(function () use ($rows): array {
            $created = 0;
            foreach ($rows as $row) {
                $this->repository->appendBusinessEvent($row);
                ++$created;
            }

            return ['created' => $created, 'ignored' => 0];
        });
    }

    /** @return array<string, int> */
    public function project(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $events = $this->repository->eventsAfterCursor($limit);
        $last = $this->repository->projectionCursor();
        $projected = 0;
        foreach ($events as $event) {
            $last = (int) $event['id'];
            $this->transactions->run(function () use ($event, $last): void {
                $this->repository->projectEvent($event);
                $this->repository->advanceProjectionCursor($last);
            });
            ++$projected;
        }

        return ['projected' => $projected, 'cursor' => $last, 'remaining_hint' => count($events) === $limit ? 1 : 0];
    }

    /** @return array<string, int> */
    public function snapshot(string $date = ''): array
    {
        $day = $this->date($date !== '' ? $date : gmdate('Y-m-d'), 'Snapshot date')->format('Y-m-d');

        return $this->transactions->run(fn (): array => $this->repository->captureInventorySnapshot($day));
    }

    /** @return array<string, int> */
    public function evaluateAlerts(): array
    {
        $candidates = $this->repository->alertCandidates(gmdate('Y-m-d H:i:s'));

        return $this->transactions->run(function () use ($candidates): array {
            $created = 0;
            $updated = 0;
            foreach ($candidates as $candidate) {
                $alert = $this->repository->upsertAlert($candidate);
                (bool) ($alert['created'] ?? false) ? ++$created : ++$updated;
            }

            return ['candidates' => count($candidates), 'created' => $created, 'updated' => $updated];
        });
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(string $type, array $filters = []): array
    {
        $filters = $this->normalizeDashboardFilters($filters);
        $dashboard = match ($type) {
            'executive' => $this->repository->executiveDashboard($filters),
            'sales' => $this->repository->salesDashboard($filters),
            'inventory' => $this->repository->inventoryDashboard($filters),
            'finance' => $this->repository->financeDashboard($filters),
            'customers' => $this->repository->customerDashboard($filters),
            default => throw new AnalyticsDomainException('Dashboard type is invalid.'),
        };
        if (isset($dashboard['revenue_irr'], $dashboard['gross_profit_irr'])) {
            $dashboard['margin_basis_points'] = $this->math->marginBasisPoints(
                (int) $dashboard['revenue_irr'],
                (int) $dashboard['gross_profit_irr']
            );
        }
        if ($type === 'executive') {
            $dashboard['targets'] = $this->targets(['active_on' => $filters['to']]);
        }

        return $dashboard + ['filters' => $filters];
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function events(array $filters = []): array
    {
        return $this->repository->events($filters);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function alerts(array $filters = []): array
    {
        return $this->repository->alerts($filters);
    }

    /** @return array<string, mixed> */
    public function updateAlert(int $alertId, string $status, int $actorUserId): array
    {
        if ($alertId <= 0) {
            throw new AnalyticsDomainException('Alert id must be positive.');
        }
        $status = $this->enum($status, self::ALERT_STATUSES, 'Alert status');

        return $this->transactions->run(function () use ($alertId, $status, $actorUserId): array {
            $alert = $this->repository->updateAlert($alertId, $status, max(1, $actorUserId));
            $this->audit->record('analytics.alert.' . $status, 'analytics_alert', (string) $alertId, [
                'status' => $status,
            ]);

            return $alert;
        });
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function normalizeDashboardFilters(array $filters): array
    {
        $from = $this->date((string) ($filters['from'] ?? gmdate('Y-m-01')), 'Dashboard from');
        $to = $this->date((string) ($filters['to'] ?? gmdate('Y-m-d')), 'Dashboard to');
        if ($to < $from) {
            throw new AnalyticsDomainException('Dashboard end date must not be before start date.');
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'sales_channel' => $this->nullableCode($filters['sales_channel'] ?? null),
            'product_line' => $this->optionalText($filters['product_line'] ?? null, 100),
            'province' => $this->optionalText($filters['province'] ?? null, 100),
            'city' => $this->optionalText($filters['city'] ?? null, 100),
        ];
    }

    private function requiredText(string $value, string $label, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new AnalyticsDomainException($label . ' is required and must fit the allowed length.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $maxLength) {
            throw new AnalyticsDomainException('Text value exceeds the allowed length.');
        }

        return $value;
    }

    private function code(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,59}$/', $value)) {
            throw new AnalyticsDomainException($label . ' must be a lowercase machine code.');
        }

        return $value;
    }

    private function nullableCode(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : $this->code((string) $value, 'Code');
    }

    private function enum(string $value, array $allowed, string $label): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new AnalyticsDomainException($label . ' is invalid.');
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer <= 0) {
            throw new AnalyticsDomainException($label . ' must be a positive integer.');
        }

        return $integer;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInt($value, 'Identifier');
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new AnalyticsDomainException($label . ' must be a non-negative integer.');
        }

        return $integer;
    }

    private function dateTime(string $value, string $label): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new AnalyticsDomainException($label . ' is invalid.');
        }
    }

    private function date(string $value, string $label): DateTimeImmutable
    {
        $date = $this->dateTime($value, $label);
        if ($date->format('Y-m-d') !== $value) {
            throw new AnalyticsDomainException($label . ' must use YYYY-MM-DD.');
        }

        return $date;
    }

    private function uuid(string $value): string
    {
        if ($value === '') {
            return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->fallbackUuid();
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new AnalyticsDomainException('UUID value is invalid.');
        }

        return strtolower($value);
    }

    private function nullableUuid(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : $this->uuid((string) $value);
    }

    private function fallbackUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
