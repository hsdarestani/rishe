<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations\Fakes;

use Rishe\Operations\Application\OperationsRepository;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use RuntimeException;

final class InMemoryOperationsRepository implements OperationsRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $jobs = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $events = [];
    /** @var array<int, array<string, mixed>> */
    public array $incidents = [];
    private int $jobSequence = 0;
    private int $incidentSequence = 0;

    public function createJob(array $data): array
    {
        foreach ($this->jobs as $job) {
            if ($job['idempotency_key'] !== $data['idempotency_key']) {
                continue;
            }
            if ($job['request_hash'] !== $data['request_hash']) {
                throw new OperationsDomainException('Operation idempotency key was reused with different inputs.');
            }

            return ['id' => $job['id'], 'idempotent' => true];
        }
        $id = ++$this->jobSequence;
        $this->jobs[$id] = array_merge($data, [
            'id' => $id,
            'public_id' => 'job-' . $id,
            'attempts' => 0,
            'result' => null,
            'last_error' => null,
            'next_retry_at' => null,
            'lock_token' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->events[$id] = [];

        return ['id' => $id, 'idempotent' => false];
    }

    public function job(int $jobId): ?array
    {
        $job = $this->jobs[$jobId] ?? null;
        if ($job !== null) {
            unset($job['lock_token']);
        }

        return $job;
    }

    public function jobForUpdate(int $jobId): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function markRunning(int $jobId, array $data): void
    {
        $this->jobs[$jobId]['status'] = 'running';
        $this->jobs[$jobId]['attempts'] = $data['attempts'];
        $this->jobs[$jobId]['lock_token'] = $data['lock_token'];
        $this->jobs[$jobId]['started_at'] = $data['started_at'];
        $this->jobs[$jobId]['locked_at'] = gmdate('Y-m-d H:i:s');
    }

    public function markCompleted(int $jobId, string $lockToken, array $result): void
    {
        if ($this->jobs[$jobId]['lock_token'] !== $lockToken) {
            throw new RuntimeException('Execution lock changed.');
        }
        $this->jobs[$jobId]['status'] = 'completed';
        $this->jobs[$jobId]['result'] = $result;
        $this->jobs[$jobId]['lock_token'] = null;
    }

    public function markFailed(
        int $jobId,
        string $lockToken,
        string $status,
        string $error,
        ?string $nextRetryAt
    ): void {
        if ($this->jobs[$jobId]['lock_token'] !== $lockToken) {
            throw new RuntimeException('Execution lock changed.');
        }
        $this->jobs[$jobId]['status'] = $status;
        $this->jobs[$jobId]['last_error'] = $error;
        $this->jobs[$jobId]['next_retry_at'] = $nextRetryAt;
        $this->jobs[$jobId]['scheduled_at'] = $nextRetryAt ?? gmdate('Y-m-d H:i:s');
        $this->jobs[$jobId]['lock_token'] = null;
    }

    public function requeue(int $jobId, string $scheduledAt): void
    {
        $this->jobs[$jobId]['status'] = 'pending';
        $this->jobs[$jobId]['scheduled_at'] = $scheduledAt;
        $this->jobs[$jobId]['next_retry_at'] = null;
        $this->jobs[$jobId]['last_error'] = null;
    }

    public function cancel(int $jobId): void
    {
        $this->jobs[$jobId]['status'] = 'cancelled';
    }

    public function staleRunningJobs(string $lockedBefore): array
    {
        return array_values(array_filter(
            $this->jobs,
            static fn (array $job): bool => $job['status'] === 'running'
                && isset($job['locked_at'])
                && $job['locked_at'] < $lockedBefore
        ));
    }

    public function recoverStaleJob(
        int $jobId,
        string $status,
        string $scheduledAt,
        string $error
    ): void {
        $this->jobs[$jobId]['status'] = $status;
        $this->jobs[$jobId]['scheduled_at'] = $scheduledAt;
        $this->jobs[$jobId]['next_retry_at'] = $status === 'retry_wait' ? $scheduledAt : null;
        $this->jobs[$jobId]['last_error'] = $error;
        $this->jobs[$jobId]['lock_token'] = null;
    }

    public function jobs(array $filters): array
    {
        return array_values(array_filter($this->jobs, static function (array $job) use ($filters): bool {
            foreach ($filters as $field => $value) {
                if ($value !== null && $value !== '' && $job[$field] !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function jobEvents(int $jobId): array
    {
        return $this->events[$jobId] ?? [];
    }

    public function appendJobEvent(int $jobId, array $event): void
    {
        $this->events[$jobId][] = $event + ['id' => count($this->events[$jobId]) + 1, 'job_id' => $jobId];
    }

    public function recordIncident(array $incident): array
    {
        foreach ($this->incidents as $id => &$existing) {
            if ($existing['fingerprint'] === $incident['fingerprint']) {
                ++$existing['occurrences'];
                $existing['status'] = 'open';

                return ['id' => $id, 'created' => false];
            }
        }
        unset($existing);
        $id = ++$this->incidentSequence;
        $this->incidents[$id] = $incident + [
            'id' => $id,
            'status' => 'open',
            'occurrences' => 1,
            'last_seen_at' => gmdate('Y-m-d H:i:s'),
        ];

        return ['id' => $id, 'created' => true];
    }

    public function incidents(array $filters): array
    {
        return array_values(array_filter($this->incidents, static function (array $incident) use ($filters): bool {
            foreach ($filters as $field => $value) {
                if ($value !== null && $value !== '' && $incident[$field] !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function updateIncidentStatus(int $incidentId, string $status, int $actorUserId): void
    {
        $this->incidents[$incidentId]['status'] = $status;
        $this->incidents[$incidentId][$status . '_by'] = $actorUserId;
    }

    public function metrics(): array
    {
        $metrics = [
            'jobs_pending' => 0,
            'jobs_running' => 0,
            'jobs_failed' => 0,
            'jobs_stale' => 0,
            'incidents_open' => 0,
            'incidents_critical' => 0,
            'outbox_pending' => 0,
            'outbox_failed' => 0,
            'tax_rejected' => 0,
            'shipment_exceptions' => 0,
        ];
        foreach ($this->jobs as $job) {
            if (in_array($job['status'], ['pending', 'retry_wait'], true)) {
                ++$metrics['jobs_pending'];
            }
            if ($job['status'] === 'running') {
                ++$metrics['jobs_running'];
            }
            if ($job['status'] === 'failed') {
                ++$metrics['jobs_failed'];
            }
        }
        foreach ($this->incidents as $incident) {
            if ($incident['status'] !== 'resolved') {
                ++$metrics['incidents_open'];
            }
        }

        return $metrics;
    }

    public function recentAudit(int $limit = 25): array
    {
        return [];
    }
}
