<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure\WordPress;

use Rishe\Analytics\Application\AnalyticsService;
use Throwable;

final class AnalyticsRuntime
{
    public const MAINTENANCE_HOOK = 'rishe_analytics_maintenance';
    private const GROUP = 'rishe-analytics';
    private AnalyticsService $service;

    public function __construct(?AnalyticsService $service = null)
    {
        $this->service = $service ?? (new AnalyticsServiceFactory())->service();
    }

    public function register(): void
    {
        add_action('rishe/audit_recorded', [$this, 'onAuditRecorded'], 10, 1);
        add_action(self::MAINTENANCE_HOOK, [$this, 'runMaintenance']);
        add_action('init', [$this, 'ensureMaintenance']);
        add_action('action_scheduler_init', [$this, 'ensureMaintenance']);
    }

    /** @param array<string, mixed> $event */
    public function onAuditRecorded(array $event): void
    {
        try {
            $this->service->ingestAuditEvent($event);
        } catch (Throwable $exception) {
            error_log('Rishe analytics event ingestion failed: ' . $exception->getMessage());
        }
    }

    public function runMaintenance(): void
    {
        try {
            do {
                $result = $this->service->project(1000);
            } while ((int) $result['remaining_hint'] === 1);
            $this->service->snapshot(gmdate('Y-m-d'));
            $this->service->evaluateAlerts();
        } catch (Throwable $exception) {
            error_log('Rishe analytics maintenance failed: ' . $exception->getMessage());
        }
    }

    public function ensureMaintenance(): void
    {
        if (function_exists('as_schedule_recurring_action')) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::MAINTENANCE_HOOK, [], self::GROUP)) {
                return;
            }
            if (function_exists('as_next_scheduled_action') && as_next_scheduled_action(self::MAINTENANCE_HOOK, [], self::GROUP) !== false) {
                return;
            }
            as_schedule_recurring_action(time() + 300, 300, self::MAINTENANCE_HOOK, [], self::GROUP, true);
            return;
        }
        if (wp_next_scheduled(self::MAINTENANCE_HOOK) === false) {
            wp_schedule_event(time() + 300, 'rishe_five_minutes', self::MAINTENANCE_HOOK);
        }
    }
}
