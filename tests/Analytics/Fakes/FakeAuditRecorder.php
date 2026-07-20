<?php

declare(strict_types=1);

namespace Rishe\Tests\Analytics;

use Rishe\Shared\Audit\AuditRecorder;

final class FakeAuditRecorder implements AuditRecorder
{
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string {
        return $eventType . ':' . $aggregateType . ':' . $aggregateId;
    }
}
