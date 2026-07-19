<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface JobHandler
{
    public function type(): string;

    /** @param array<string, mixed> $job @return array<string, mixed> */
    public function handle(array $job): array;
}
