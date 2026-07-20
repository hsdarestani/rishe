<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\JobScheduler;
use RuntimeException;
use WP_Error;

final class WpJobScheduler implements JobScheduler
{
    public const HOOK = 'rishe_operations_run_job';
    private const GROUP = 'rishe-operations';

    public function schedule(int $jobId, string $scheduledAt): void
    {
        $timestamp = max(time() + 1, (int) strtotime($scheduledAt));
        $args = [$jobId];
        if (function_exists('as_schedule_single_action')) {
            $this->scheduleActionScheduler($timestamp, $args);

            return;
        }

        $this->scheduleWpCron($timestamp, $args);
    }

    public function backend(): string
    {
        return function_exists('as_schedule_single_action') ? 'action_scheduler' : 'wp_cron';
    }

    /** @param list<int> $args */
    private function scheduleActionScheduler(int $timestamp, array $args): void
    {
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            if ($this->hasActionSchedulerJob($args)) {
                return;
            }
            $actionId = as_schedule_single_action($timestamp, self::HOOK, $args, self::GROUP, true);
            if (is_int($actionId) && $actionId > 0) {
                return;
            }
            if ($this->hasActionSchedulerJob($args)) {
                return;
            }
            usleep($attempt * 50000);
        }

        throw new RuntimeException('Action Scheduler could not enqueue the operation job.');
    }

    /** @param list<int> $args */
    private function scheduleWpCron(int $timestamp, array $args): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            if ($this->hasWpCronJob($args)) {
                return;
            }
            $result = wp_schedule_single_event($timestamp, self::HOOK, $args, true);
            if ($result === true) {
                return;
            }
            if ($result instanceof WP_Error && $result->get_error_code() === 'duplicate_event') {
                return;
            }
            if ($this->hasWpCronJob($args, true)) {
                return;
            }
            usleep($attempt * 75000);
        }

        throw new RuntimeException('WordPress Cron could not enqueue the operation job.');
    }

    /** @param list<int> $args */
    private function hasActionSchedulerJob(array $args): bool
    {
        if (function_exists('as_has_scheduled_action')) {
            return (bool) as_has_scheduled_action(self::HOOK, $args, self::GROUP);
        }
        if (function_exists('as_next_scheduled_action')) {
            return as_next_scheduled_action(self::HOOK, $args, self::GROUP) !== false;
        }

        return false;
    }

    /** @param list<int> $args */
    private function hasWpCronJob(array $args, bool $refresh = false): bool
    {
        if ($refresh) {
            wp_cache_delete('cron', 'options');
        }

        return wp_next_scheduled(self::HOOK, $args) !== false;
    }
}
