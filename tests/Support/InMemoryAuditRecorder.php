<?php

declare(strict_types=1);

namespace Rishe\Tests\Support;

use Rishe\Shared\Audit\AuditRecorder;

final class InMemoryAuditRecorder implements AuditRecorder
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string {
        $this->events[] = [
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'correlation_id' => $correlationId,
        ];

        return 'event-' . count($this->events);
    }
}
