<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations\Fakes;

use Rishe\Operations\Application\JobScheduler;

final class InMemoryJobScheduler implements JobScheduler
{
    /** @var list<array{job_id: int, scheduled_at: string}> */
    public array $scheduled = [];

    public function schedule(int $jobId, string $scheduledAt): void
    {
        foreach ($this->scheduled as $scheduled) {
            if ($scheduled['job_id'] === $jobId && $scheduled['scheduled_at'] === $scheduledAt) {
                return;
            }
        }
        $this->scheduled[] = ['job_id' => $jobId, 'scheduled_at' => $scheduledAt];
    }

    public function backend(): string
    {
        return 'memory';
    }
}
