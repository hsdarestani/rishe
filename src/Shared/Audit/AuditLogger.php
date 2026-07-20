<?php

declare(strict_types=1);

namespace Rishe\Shared\Audit;

use JsonException;
use RuntimeException;

final class AuditLogger implements AuditRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        ?string $correlationId = null
    ): string {
        global $wpdb;

        $eventId = wp_generate_uuid4();

        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode audit payload.', 0, $exception);
        }

        $occurredAt = current_time('mysql', true);
        $actorUserId = get_current_user_id() ?: null;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'rishe_audit_log',
            [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'actor_user_id' => $actorUserId,
                'correlation_id' => $correlationId,
                'payload_json' => $payloadJson,
                'created_at' => $occurredAt,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            throw new RuntimeException('Unable to write audit log event.');
        }

        do_action('rishe/audit_recorded', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'actor_user_id' => $actorUserId,
            'correlation_id' => $correlationId,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);

        return $eventId;
    }
}
