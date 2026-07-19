<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\OperationsRepository;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use RuntimeException;

final class WpdbOperationsRepository implements OperationsRepository
{
    use WpdbOperationsJobStorage;
    use WpdbOperationsEventStorage;
    use WpdbOperationsIncidentStorage;
    use WpdbOperationsReporting;
    use WpdbOperationsStorageHelpers;

    public function createJob(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_jobs';
        $publicId = wp_generate_uuid4();
        $now = current_time('mysql', true);
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (
                public_id, job_type, aggregate_type, aggregate_id, idempotency_key, request_hash,
                payload_json, result_json, status, priority, attempts, max_attempts, scheduled_at,
                correlation_id, created_by, created_at, updated_at
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s, NULL, %s, %d, 0, %d, %s, %s, %d, %s, %s
            ) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $publicId,
            $data['job_type'],
            $data['aggregate_type'],
            $data['aggregate_id'],
            $data['idempotency_key'],
            $data['request_hash'],
            $this->encode($data['payload']),
            $data['status'],
            $data['priority'],
            $data['max_attempts'],
            $data['scheduled_at'],
            $data['correlation_id'],
            $data['created_by'],
            $now,
            $now
        );
        $executed = $wpdb->query($sql);
        if ($executed === false) {
            throw new RuntimeException('Unable to create operation job: ' . $wpdb->last_error);
        }

        $jobId = (int) $wpdb->insert_id;
        if ($jobId < 1) {
            $jobId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE idempotency_key = %s",
                $data['idempotency_key']
            ));
        }
        $stored = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $jobId), ARRAY_A);
        if (!is_array($stored)) {
            throw new RuntimeException('Operation job was not readable after atomic enqueue.');
        }
        if ((string) $stored['request_hash'] !== (string) $data['request_hash']) {
            throw new OperationsDomainException('Operation idempotency key was reused with different inputs.');
        }

        $created = hash_equals($publicId, (string) $stored['public_id']);
        if ($created) {
            $this->appendJobEvent($jobId, [
                'event_type' => 'enqueued',
                'status_from' => null,
                'status_to' => 'pending',
                'message' => 'Job was added to the operation queue.',
                'context' => ['scheduled_at' => $data['scheduled_at']],
                'actor_user_id' => $data['created_by'],
                'correlation_id' => $data['correlation_id'],
            ]);
        }

        return ['id' => $jobId, 'idempotent' => !$created];
    }
}
