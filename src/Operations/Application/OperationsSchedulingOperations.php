<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\OperationJobStatus;
use Throwable;

trait OperationsSchedulingOperations
{
    /** @return array{selected: int, scheduled: int, failed: int} */
    public function reconcileSchedules(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $jobs = array_merge(
            $this->repository->jobs(['status' => OperationJobStatus::PENDING->value]),
            $this->repository->jobs(['status' => OperationJobStatus::RETRY_WAIT->value])
        );
        usort($jobs, static function (array $left, array $right): int {
            $priority = (int) $left['priority'] <=> (int) $right['priority'];
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string) $left['scheduled_at'], (string) $right['scheduled_at']);
        });

        $result = ['selected' => 0, 'scheduled' => 0, 'failed' => 0];
        foreach (array_slice($jobs, 0, $limit) as $job) {
            ++$result['selected'];
            try {
                $this->scheduler->schedule((int) $job['id'], (string) $job['scheduled_at']);
                ++$result['scheduled'];
            } catch (Throwable $exception) {
                ++$result['failed'];
                $this->audit->record(
                    'operations.job.schedule_reconciliation_failed',
                    'operation_job',
                    (string) $job['id'],
                    ['error' => substr($exception->getMessage(), 0, 1000)],
                    $job['correlation_id'] ?? null
                );
            }
        }

        return $result;
    }
}
