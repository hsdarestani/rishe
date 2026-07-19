<?php

declare(strict_types=1);

namespace Rishe\Shared\Audit;

interface AuditRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string;
}
