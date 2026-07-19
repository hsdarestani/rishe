<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Operations\Domain\OperationJobStatus;
use RuntimeException;

trait OperationsControlOperations
{
    public function retry(int $jobId, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new OperationsDomainException('Retry requires an authenticated actor.');
        }
        $scheduledAt = gmdate('Y-m-d H:i:s');
        $job = $this->transactions->run(function () use ($jobId, $actorUserId, $scheduledAt): array {
            $job = $this->repository->jobForUpdate($this->positiveId($jobId, 'job_id'));
            if ($job === null) {
                throw new RuntimeException('Operation job not found.');
            }
            $status = $this->status($job);
            if (!in_array($status, [OperationJobStatus::FAILED, OperationJobStatus::RETRY_WAIT], true)) {
                throw new OperationsDomainException('Only failed or waiting jobs can be retried.');
            }
            if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
                throw new OperationsDomainException('Operation job exhausted its configured attempts.');
            }
            $status->assertCanTransitionTo(OperationJobStatus::PENDING);
            $this->repository->requeue((int) $job['id'], $scheduledAt);
            $this->repository->appendJobEvent((int) $job['id'], [
                'event_type' => 'manual_retry',
                'status_from' => $status->value,
                'status_to' => OperationJobStatus::PENDING->value,
                'message' => 'Job was manually requeued.',
                'context' => [],
                'actor_user_id' => $actorUserId,
                'correlation_id' => $job['correlation_id'],
            ]);

            return $job;
        });
        $this->scheduler->schedule((int) $job['id'], $scheduledAt);
        $this->audit->record('operations.job.retried', 'operation_job', (string) $job['id'], [
            'actor_user_id' => $actorUserId,
        ], $job['correlation_id'] ?? null);

        return $this->requireJob((int) $job['id']);
    }

    /** @return array<string, mixed> */
    public function cancel(int $jobId, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new OperationsDomainException('Cancellation requires an authenticated actor.');
        }
        $this->transactions->run(function () use ($jobId, $actorUserId): void {
            $job = $this->repository->jobForUpdate($this->positiveId($jobId, 'job_id'));
            if ($job === null) {
                throw new RuntimeException('Operation job not found.');
            }
            $status = $this->status($job);
            if ($status === OperationJobStatus::CANCELLED) {
                return;
            }
            $status->assertCanTransitionTo(OperationJobStatus::CANCELLED);
            $this->repository->cancel((int) $job['id']);
            $this->repository->appendJobEvent((int) $job['id'], [
                'event_type' => 'cancelled',
                'status_from' => $status->value,
                'status_to' => OperationJobStatus::CANCELLED->value,
                'message' => 'Job was cancelled by an operator.',
                'context' => [],
                'actor_user_id' => $actorUserId,
                'correlation_id' => $job['correlation_id'],
            ]);
        });
        $this->audit->record('operations.job.cancelled', 'operation_job', (string) $jobId, [
            'actor_user_id' => $actorUserId,
        ]);

        return $this->requireJob($jobId);
    }

    /** @return array<string, mixed> */
    public function job(int $jobId): array
    {
        $job = $this->requireJob($this->positiveId($jobId, 'job_id'));
        $job['events'] = $this->repository->jobEvents((int) $job['id']);

        return $job;
    }

    /** @return list<array<string, mixed>> */
    public function jobs(array $filters = []): array
    {
        return $this->repository->jobs([
            'status' => $this->nullableText($filters['status'] ?? null, 30),
            'job_type' => $this->nullableText($filters['job_type'] ?? null, 100),
            'aggregate_type' => $this->nullableText($filters['aggregate_type'] ?? null, 100),
        ]);
    }

    /** @return list<string> */
    public function jobTypes(): array
    {
        return $this->handlers->types();
    }

    /** @return list<array<string, mixed>> */
    public function incidents(array $filters = []): array
    {
        return $this->repository->incidents([
            'status' => $this->nullableText($filters['status'] ?? null, 30),
            'severity' => $this->nullableText($filters['severity'] ?? null, 20),
            'source' => $this->nullableText($filters['source'] ?? null, 100),
        ]);
    }

    /** @return array<string, mixed> */
    public function updateIncident(int $incidentId, string $status, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new OperationsDomainException('Incident update requires an authenticated actor.');
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            throw new OperationsDomainException('Incident status is invalid.');
        }
        $this->repository->updateIncidentStatus($this->positiveId($incidentId, 'incident_id'), $status, $actorUserId);
        $this->audit->record('operations.incident.' . $status, 'system_incident', (string) $incidentId, [
            'actor_user_id' => $actorUserId,
        ]);
        foreach ($this->repository->incidents([]) as $incident) {
            if ((int) $incident['id'] === $incidentId) {
                return $incident;
            }
        }

        throw new RuntimeException('Incident not found after update.');
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        return [
            'metrics' => $this->repository->metrics(),
            'jobs' => array_slice($this->repository->jobs([]), 0, 20),
            'incidents' => array_slice($this->repository->incidents(['status' => 'open']), 0, 20),
            'audit' => $this->repository->recentAudit(20),
            'job_types' => $this->handlers->types(),
            'scheduler' => $this->scheduler->backend(),
            'generated_at' => gmdate('c'),
        ];
    }
}
