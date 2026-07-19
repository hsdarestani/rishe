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
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            return $this->existingJobResult($existing, (string) $data['request_hash']);
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
            $winner = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE idempotency_key = %s",
                $data['idempotency_key']
            ), ARRAY_A);
            if (is_array($winner)) {
                return $this->existingJobResult($winner, (string) $data['request_hash']);
            }
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

    /** @param array<string, mixed> $existing @return array{id: int, idempotent: bool} */
    private function existingJobResult(array $existing, string $requestHash): array
    {
        if ((string) $existing['request_hash'] !== $requestHash) {
            throw new OperationsDomainException('Operation idempotency key was reused with different inputs.');
        }

        return ['id' => (int) $existing['id'], 'idempotent' => true];
    }
}
