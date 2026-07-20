<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\WordPress;

use Rishe\Operations\Application\OperationsService;
use Rishe\Operations\Infrastructure\WpJobScheduler;
use Throwable;

final class OperationsRuntime
{
    public const MAINTENANCE_HOOK = 'rishe_operations_maintenance';
    private const GROUP = 'rishe-operations';
    private OperationsService $service;

    public function __construct(?OperationsService $service = null)
    {
        $this->service = $service ?? (new OperationsServiceFactory())->operations();
    }

    public function register(): void
    {
        add_action(WpJobScheduler::HOOK, [$this, 'runJob'], 10, 1);
        add_action(self::MAINTENANCE_HOOK, [$this, 'runMaintenance']);
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [$this, 'ensureMaintenance']);
        add_action('action_scheduler_init', [$this, 'ensureMaintenance']);
    }

    public function runJob(int $jobId): void
    {
        try {
            $this->service->execute($jobId);
        } catch (Throwable $exception) {
            error_log(sprintf('Rishe operation job %d runtime failure: %s', $jobId, $exception->getMessage()));
        }
    }

    public function runMaintenance(): void
    {
        try {
            $this->service->recoverStaleJobs();
            $this->service->reconcileSchedules();
        } catch (Throwable $exception) {
            error_log('Rishe operations maintenance failure: ' . $exception->getMessage());
        }
    }

    /** @param array<string, array<string, mixed>> $schedules @return array<string, array<string, mixed>> */
    public function cronSchedules(array $schedules): array
    {
        $schedules['rishe_five_minutes'] = [
            'interval' => 300,
            'display' => __('هر پنج دقیقه یک‌بار (سامانه ریشه)', 'rishe'),
        ];

        return $schedules;
    }

    public function ensureMaintenance(): void
    {
        if (function_exists('as_schedule_recurring_action')) {
            if (function_exists('as_has_scheduled_action')) {
                if (as_has_scheduled_action(self::MAINTENANCE_HOOK, [], self::GROUP)) {
                    return;
                }
            } elseif (
                function_exists('as_next_scheduled_action')
                && as_next_scheduled_action(self::MAINTENANCE_HOOK, [], self::GROUP) !== false
            ) {
                return;
            }
            as_schedule_recurring_action(
                time() + 300,
                300,
                self::MAINTENANCE_HOOK,
                [],
                self::GROUP,
                true
            );

            return;
        }
        if (wp_next_scheduled(self::MAINTENANCE_HOOK) === false) {
            wp_schedule_event(time() + 300, 'rishe_five_minutes', self::MAINTENANCE_HOOK);
        }
    }
}
