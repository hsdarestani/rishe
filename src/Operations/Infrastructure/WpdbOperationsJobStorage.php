<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Domain\Exception\OperationsDomainException;
use RuntimeException;

trait WpdbOperationsJobStorage
{
    public function createJob(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_jobs';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['request_hash'] !== (string) $data['request_hash']) {
                throw new OperationsDomainException('Operation idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, [
            'public_id' => wp_generate_uuid4(),
            'job_type' => $data['job_type'],
            'aggregate_type' => $data['aggregate_type'],
            'aggregate_id' => $data['aggregate_id'],
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $data['request_hash'],
            'payload_json' => $this->encode($data['payload']),
            'result_json' => null,
            'status' => $data['status'],
            'priority' => $data['priority'],
            'attempts' => 0,
            'max_attempts' => $data['max_attempts'],
            'scheduled_at' => $data['scheduled_at'],
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['created_by'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s',
        ]);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create operation job: ' . $wpdb->last_error);
        }
        $jobId = (int) $wpdb->insert_id;
        $this->appendJobEvent($jobId, [
            'event_type' => 'enqueued',
            'status_from' => null,
            'status_to' => 'pending',
            'message' => 'Job was added to the operation queue.',
            'context' => ['scheduled_at' => $data['scheduled_at']],
            'actor_user_id' => $data['created_by'],
            'correlation_id' => $data['correlation_id'],
        ]);

        return ['id' => $jobId, 'idempotent' => false];
    }

    public function job(int $jobId): ?array
    {
        return $this->findJob($jobId, false);
    }

    public function jobForUpdate(int $jobId): ?array
    {
        return $this->findJob($jobId, true);
    }

    public function markRunning(int $jobId, array $data): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => 'running',
            'attempts' => $data['attempts'],
            'lock_token' => $data['lock_token'],
            'locked_at' => current_time('mysql', true),
            'started_at' => $data['started_at'],
            'next_retry_at' => null,
            'last_error' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId], ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to claim operation job.');
        }
    }

    public function markCompleted(int $jobId, string $lockToken, array $result): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => 'completed',
            'result_json' => $this->encode($result),
            'lock_token' => null,
            'locked_at' => null,
            'finished_at' => current_time('mysql', true),
            'last_error' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId, 'status' => 'running', 'lock_token' => $lockToken], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ], ['%d', '%s', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to complete operation job because its execution lock changed.');
        }
    }

    public function markFailed(
        int $jobId,
        string $lockToken,
        string $status,
        string $error,
        ?string $nextRetryAt
    ): void {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => $status,
            'scheduled_at' => $nextRetryAt ?? current_time('mysql', true),
            'next_retry_at' => $nextRetryAt,
            'lock_token' => null,
            'locked_at' => null,
            'finished_at' => $status === 'failed' ? current_time('mysql', true) : null,
            'last_error' => $error,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId, 'status' => 'running', 'lock_token' => $lockToken], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ], ['%d', '%s', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to fail operation job because its execution lock changed.');
        }
    }

    public function requeue(int $jobId, string $scheduledAt): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => 'pending',
            'scheduled_at' => $scheduledAt,
            'next_retry_at' => null,
            'finished_at' => null,
            'last_error' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId], ['%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to requeue operation job.');
        }
    }

    public function cancel(int $jobId): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => 'cancelled',
            'finished_at' => current_time('mysql', true),
            'lock_token' => null,
            'locked_at' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to cancel operation job.');
        }
    }

    public function staleRunningJobs(string $lockedBefore): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_jobs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'running' AND locked_at IS NOT NULL AND locked_at < %s
             ORDER BY locked_at ASC LIMIT 100",
            $lockedBefore
        ), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatJob'], $rows) : [];
    }

    public function recoverStaleJob(
        int $jobId,
        string $status,
        string $scheduledAt,
        string $error
    ): void {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_operation_jobs', [
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'next_retry_at' => $status === 'retry_wait' ? $scheduledAt : null,
            'lock_token' => null,
            'locked_at' => null,
            'finished_at' => $status === 'failed' ? current_time('mysql', true) : null,
            'last_error' => $error,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $jobId, 'status' => 'running'], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ], ['%d', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to recover stale operation job.');
        }
    }

    public function jobs(array $filters): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_jobs';
        $clauses = ['1=1'];
        $args = [];
        foreach (['status', 'job_type', 'aggregate_type'] as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $clauses[] = "{$field} = %s";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) .
            " ORDER BY priority ASC, scheduled_at DESC, id DESC LIMIT 250";
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatJob'], $rows) : [];
    }
}
