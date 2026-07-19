<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface OperationsRepository
{
    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool} */
    public function createJob(array $data): array;

    /** @return array<string, mixed>|null */
    public function job(int $jobId): ?array;

    /** @return array<string, mixed>|null */
    public function jobForUpdate(int $jobId): ?array;

    /** @param array<string, mixed> $data */
    public function markRunning(int $jobId, array $data): void;

    /** @param array<string, mixed> $result */
    public function markCompleted(int $jobId, string $lockToken, array $result): void;

    public function markFailed(
        int $jobId,
        string $lockToken,
        string $status,
        string $error,
        ?string $nextRetryAt
    ): void;

    public function requeue(int $jobId, string $scheduledAt): void;

    public function cancel(int $jobId): void;

    /** @return list<array<string, mixed>> */
    public function staleRunningJobs(string $lockedBefore): array;

    public function recoverStaleJob(
        int $jobId,
        string $status,
        string $scheduledAt,
        string $error
    ): void;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function jobs(array $filters): array;

    /** @return list<array<string, mixed>> */
    public function jobEvents(int $jobId): array;

    /** @param array<string, mixed> $event */
    public function appendJobEvent(int $jobId, array $event): void;

    /** @param array<string, mixed> $incident @return array{id: int, created: bool} */
    public function recordIncident(array $incident): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function incidents(array $filters): array;

    public function updateIncidentStatus(int $incidentId, string $status, int $actorUserId): void;

    /** @return array<string, int> */
    public function metrics(): array;

    /** @return list<array<string, mixed>> */
    public function recentAudit(int $limit = 25): array;
}
