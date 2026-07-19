<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface JobHandlerRegistry
{
    public function has(string $jobType): bool;

    public function handler(string $jobType): JobHandler;

    /** @return list<string> */
    public function types(): array;
}
