<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\OperationJobStatus;
use Throwable;

trait OperationsFailureOperations
{
    private function handleFailure(array $job, string $lockToken, Throwable $exception): void
    {
        $attempt = (int) $job['attempts'];
        $willRetry = $attempt < (int) $job['max_attempts'];
        $nextRetryAt = null;
        $nextStatus = OperationJobStatus::FAILED;
        if ($willRetry) {
            $nextRetryAt = gmdate('Y-m-d H:i:s', time() + $this->backoff->delayForAttempt($attempt));
            $nextStatus = OperationJobStatus::RETRY_WAIT;
        }
        $message = substr($exception->getMessage(), 0, 2000);
        $exceptionClass = $exception::class;
        $this->transactions->run(function () use (
            $job,
            $lockToken,
            $nextStatus,
            $message,
            $nextRetryAt,
            $attempt,
            $exceptionClass
        ): void {
            $this->repository->markFailed(
                (int) $job['id'],
                $lockToken,
                $nextStatus->value,
                $message,
                $nextRetryAt
            );
            $this->repository->appendJobEvent((int) $job['id'], [
                'event_type' => $nextStatus === OperationJobStatus::RETRY_WAIT ? 'retry_scheduled' : 'failed',
                'status_from' => OperationJobStatus::RUNNING->value,
                'status_to' => $nextStatus->value,
                'message' => $message,
                'context' => ['attempt' => $attempt, 'exception' => $exceptionClass],
                'actor_user_id' => $job['created_by'],
                'correlation_id' => $job['correlation_id'],
            ]);
        });
        if ($willRetry && $nextRetryAt !== null) {
            $this->scheduler->schedule((int) $job['id'], $nextRetryAt);
        } else {
            $this->recordTerminalIncident($job, $message, $exceptionClass);
        }
        $this->audit->record('operations.job.' . $nextStatus->value, 'operation_job', (string) $job['id'], [
            'job_type' => $job['job_type'],
            'attempt' => $attempt,
            'error' => $message,
            'next_retry_at' => $nextRetryAt,
        ], $job['correlation_id'] ?? null);
    }

    /** @param array<string, mixed> $job */
    private function recordTerminalIncident(array $job, string $message, string $exceptionClass = 'stale_lock'): void
    {
        $fingerprint = hash('sha256', implode('|', [
            'operation_job',
            (string) $job['job_type'],
            (string) $job['aggregate_type'],
            (string) $job['aggregate_id'],
            $exceptionClass,
        ]));
        $this->repository->recordIncident([
            'fingerprint' => $fingerprint,
            'severity' => 'error',
            'source' => 'operation_job',
            'code' => (string) $job['job_type'],
            'message' => $message,
            'context' => [
                'job_id' => (int) $job['id'],
                'aggregate_type' => $job['aggregate_type'],
                'aggregate_id' => $job['aggregate_id'],
                'attempts' => (int) $job['attempts'],
            ],
            'correlation_id' => $job['correlation_id'],
        ]);
    }
}
