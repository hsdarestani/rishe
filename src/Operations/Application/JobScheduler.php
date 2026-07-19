<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface JobScheduler
{
    public function schedule(int $jobId, string $scheduledAt): void;

    public function backend(): string;
}
