<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Operations\Domain\OperationJobStatus;
use RuntimeException;
use Throwable;

trait OperationsValidation
{
    private function requireJob(int $jobId): array
    {
        $job = $this->repository->job($jobId);
        if ($job === null) {
            throw new RuntimeException('Operation job not found.');
        }

        return $job;
    }

    /** @param array<string, mixed> $job */
    private function status(array $job): OperationJobStatus
    {
        $status = OperationJobStatus::tryFrom((string) ($job['status'] ?? ''));
        if ($status === null) {
            throw new OperationsDomainException('Operation job status is invalid.');
        }

        return $status;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new OperationsDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function priority(mixed $value): int
    {
        $priority = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($priority === false) {
            throw new OperationsDomainException('Job priority must be between 0 and 100.');
        }

        return (int) $priority;
    }

    private function dateTime(mixed $value): string
    {
        try {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new OperationsDomainException('Scheduled time is invalid.');
        }
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > $maximum) {
            throw new OperationsDomainException('Text value is too long.');
        }

        return $text;
    }
}
