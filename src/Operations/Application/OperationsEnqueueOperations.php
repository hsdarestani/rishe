<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Operations\Domain\OperationJobStatus;

trait OperationsEnqueueOperations
{
    public function enqueue(array $data, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new OperationsDomainException('Operation job requires an authenticated actor.');
        }
        $jobType = strtolower(trim((string) ($data['job_type'] ?? '')));
        if (!$this->handlers->has($jobType)) {
            throw new OperationsDomainException('Operation job type is not registered.');
        }
        $aggregateType = strtolower(trim((string) ($data['aggregate_type'] ?? 'operation')));
        $aggregateId = trim((string) ($data['aggregate_id'] ?? ''));
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        $payload = $data['payload'] ?? [];
        if ($aggregateId === '' || strlen($aggregateId) > 191) {
            throw new OperationsDomainException('Operation aggregate id is required and is too long.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new OperationsDomainException('Operation idempotency key is required and is too long.');
        }
        if (!is_array($payload)) {
            throw new OperationsDomainException('Operation payload must be a JSON object.');
        }
        $maxAttempts = filter_var($data['max_attempts'] ?? 5, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 20],
        ]);
        if ($maxAttempts === false) {
            throw new OperationsDomainException('Maximum attempts must be between 1 and 20.');
        }
        $scheduledAt = $this->dateTime($data['scheduled_at'] ?? gmdate('c'));
        $requestHash = hash('sha256', json_encode([
            'job_type' => $jobType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $result = $this->transactions->run(fn (): array => $this->repository->createJob([
            'job_type' => $jobType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'payload' => $payload,
            'status' => OperationJobStatus::PENDING->value,
            'priority' => $this->priority($data['priority'] ?? 10),
            'max_attempts' => (int) $maxAttempts,
            'scheduled_at' => $scheduledAt,
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'created_by' => $actorUserId,
        ]));

        $job = $this->requireJob((int) $result['id']);
        if (in_array($job['status'], [OperationJobStatus::PENDING->value, OperationJobStatus::RETRY_WAIT->value], true)) {
            $this->scheduler->schedule((int) $job['id'], (string) $job['scheduled_at']);
        }
        if (!$result['idempotent']) {
            $this->audit->record('operations.job.enqueued', 'operation_job', (string) $job['id'], [
                'job_type' => $jobType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'scheduler' => $this->scheduler->backend(),
            ], $job['correlation_id'] ?? null);
        }

        return $job + ['idempotent' => (bool) $result['idempotent']];
    }
}
