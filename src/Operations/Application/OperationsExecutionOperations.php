<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Operations\Domain\OperationJobStatus;
use RuntimeException;
use Throwable;

trait OperationsExecutionOperations
{
    public function execute(int $jobId): array
    {
        $lockToken = bin2hex(random_bytes(16));
        $claimed = $this->transactions->run(function () use ($jobId, $lockToken): ?array {
            $job = $this->repository->jobForUpdate($this->positiveId($jobId, 'job_id'));
            if ($job === null) {
                throw new RuntimeException('Operation job not found.');
            }
            $status = $this->status($job);
            if (in_array($status, [OperationJobStatus::COMPLETED, OperationJobStatus::CANCELLED], true)) {
                return null;
            }
            if (!in_array($status, [OperationJobStatus::PENDING, OperationJobStatus::RETRY_WAIT], true)) {
                return null;
            }
            if (strtotime((string) $job['scheduled_at']) > time()) {
                return null;
            }
            $status->assertCanTransitionTo(OperationJobStatus::RUNNING);
            $attempts = (int) $job['attempts'] + 1;
            $this->repository->markRunning((int) $job['id'], [
                'lock_token' => $lockToken,
                'attempts' => $attempts,
                'started_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->repository->appendJobEvent((int) $job['id'], [
                'event_type' => 'started',
                'status_from' => $status->value,
                'status_to' => OperationJobStatus::RUNNING->value,
                'message' => 'Job execution started.',
                'context' => ['attempt' => $attempts],
                'actor_user_id' => $job['created_by'],
                'correlation_id' => $job['correlation_id'],
            ]);
            $job['status'] = OperationJobStatus::RUNNING->value;
            $job['attempts'] = $attempts;
            $job['lock_token'] = $lockToken;

            return $job;
        });

        if ($claimed === null) {
            return $this->requireJob($jobId);
        }

        try {
            $result = $this->handlers->handler((string) $claimed['job_type'])->handle($claimed);
            $this->transactions->run(function () use ($claimed, $lockToken, $result): void {
                $this->repository->markCompleted((int) $claimed['id'], $lockToken, $result);
                $this->repository->appendJobEvent((int) $claimed['id'], [
                    'event_type' => 'completed',
                    'status_from' => OperationJobStatus::RUNNING->value,
                    'status_to' => OperationJobStatus::COMPLETED->value,
                    'message' => 'Job execution completed.',
                    'context' => $result,
                    'actor_user_id' => $claimed['created_by'],
                    'correlation_id' => $claimed['correlation_id'],
                ]);
            });
            $this->audit->record('operations.job.completed', 'operation_job', (string) $claimed['id'], [
                'job_type' => $claimed['job_type'],
                'attempt' => $claimed['attempts'],
            ], $claimed['correlation_id'] ?? null);
        } catch (Throwable $exception) {
            $this->handleFailure($claimed, $lockToken, $exception);
        }

        return $this->requireJob($jobId);
    }

    /** @return array{recovered: int, failed: int} */
    public function recoverStaleJobs(int $lockTimeoutSeconds = 900): array
    {
        if ($lockTimeoutSeconds < 60) {
            throw new OperationsDomainException('Operation lock timeout must be at least 60 seconds.');
        }
        $lockedBefore = gmdate('Y-m-d H:i:s', time() - $lockTimeoutSeconds);
        $recovered = 0;
        $failed = 0;
        foreach ($this->repository->staleRunningJobs($lockedBefore) as $stale) {
            $result = $this->transactions->run(function () use ($stale, $lockedBefore): ?array {
                $job = $this->repository->jobForUpdate((int) $stale['id']);
                if ($job === null || (string) $job['status'] !== OperationJobStatus::RUNNING->value) {
                    return null;
                }
                if (($job['locked_at'] ?? null) === null || (string) $job['locked_at'] >= $lockedBefore) {
                    return null;
                }
                $willRetry = (int) $job['attempts'] < (int) $job['max_attempts'];
                $status = $willRetry ? OperationJobStatus::RETRY_WAIT : OperationJobStatus::FAILED;
                $scheduledAt = gmdate('Y-m-d H:i:s');
                $error = 'Operation worker lock expired before completion.';
                $this->repository->recoverStaleJob((int) $job['id'], $status->value, $scheduledAt, $error);
                $this->repository->appendJobEvent((int) $job['id'], [
                    'event_type' => $willRetry ? 'stale_lock_recovered' : 'stale_lock_failed',
                    'status_from' => OperationJobStatus::RUNNING->value,
                    'status_to' => $status->value,
                    'message' => $error,
                    'context' => ['locked_at' => $job['locked_at'], 'attempts' => $job['attempts']],
                    'actor_user_id' => $job['created_by'],
                    'correlation_id' => $job['correlation_id'],
                ]);

                return ['job' => $job, 'status' => $status->value, 'scheduled_at' => $scheduledAt];
            });
            if ($result === null) {
                continue;
            }
            if ($result['status'] === OperationJobStatus::RETRY_WAIT->value) {
                $this->scheduler->schedule((int) $result['job']['id'], (string) $result['scheduled_at']);
                ++$recovered;
            } else {
                $this->recordTerminalIncident($result['job'], 'Operation worker lock expired before completion.');
                ++$failed;
            }
            $this->audit->record(
                'operations.job.stale_lock_recovered',
                'operation_job',
                (string) $result['job']['id'],
                ['status' => $result['status']],
                $result['job']['correlation_id'] ?? null
            );
        }

        return ['recovered' => $recovered, 'failed' => $failed];
    }
}
