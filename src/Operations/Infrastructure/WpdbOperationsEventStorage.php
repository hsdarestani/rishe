<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use RuntimeException;

trait WpdbOperationsEventStorage
{
    public function jobEvents(int $jobId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_job_events';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatJobEvent'], $rows) : [];
    }

    public function appendJobEvent(int $jobId, array $event): void
    {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . 'rishe_operation_job_events', [
            'event_id' => wp_generate_uuid4(),
            'job_id' => $jobId,
            'event_type' => $event['event_type'],
            'status_from' => $event['status_from'],
            'status_to' => $event['status_to'],
            'message' => $event['message'],
            'context_json' => $this->encode($event['context'] ?? []),
            'actor_user_id' => $event['actor_user_id'] ?: null,
            'correlation_id' => $event['correlation_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        if ($inserted === false) {
            throw new RuntimeException('Unable to append operation job event: ' . $wpdb->last_error);
        }
    }
}
