<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations\Fakes;

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
        $this->events[] = compact('eventType', 'aggregateType', 'aggregateId', 'payload', 'correlationId');

        return 'audit-' . count($this->events);
    }
}
