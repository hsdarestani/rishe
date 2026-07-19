<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\Handlers;

use Rishe\Operations\Application\JobHandler;

final class SystemNoopJobHandler implements JobHandler
{
    public function type(): string
    {
        return 'system.noop';
    }

    public function handle(array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $delayMs = max(0, min(1000, (int) ($payload['delay_ms'] ?? 0)));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        return [
            'ok' => true,
            'job_id' => (int) ($job['id'] ?? 0),
            'delay_ms' => $delayMs,
            'completed_at' => gmdate('c'),
        ];
    }
}
