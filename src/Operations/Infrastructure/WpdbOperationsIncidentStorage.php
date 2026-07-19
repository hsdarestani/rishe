<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use RuntimeException;

trait WpdbOperationsIncidentStorage
{
    public function recordIncident(array $incident): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_system_incidents';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE fingerprint = %s FOR UPDATE",
            $incident['fingerprint']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        if (is_array($existing)) {
            $updated = $wpdb->update($table, [
                'severity' => $incident['severity'],
                'message' => $incident['message'],
                'context_json' => $this->encode($incident['context'] ?? []),
                'status' => 'open',
                'occurrences' => (int) $existing['occurrences'] + 1,
                'last_seen_at' => $now,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'resolved_by' => null,
                'resolved_at' => null,
                'correlation_id' => $incident['correlation_id'],
                'updated_at' => $now,
            ], ['id' => $existing['id']], [
                '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s',
            ], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('Unable to update system incident: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $inserted = $wpdb->insert($table, [
            'incident_id' => wp_generate_uuid4(),
            'fingerprint' => $incident['fingerprint'],
            'severity' => $incident['severity'],
            'source' => $incident['source'],
            'code' => $incident['code'],
            'message' => $incident['message'],
            'context_json' => $this->encode($incident['context'] ?? []),
            'status' => 'open',
            'occurrences' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'correlation_id' => $incident['correlation_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create system incident: ' . $wpdb->last_error);
        }

        return ['id' => (int) $wpdb->insert_id, 'created' => true];
    }

    public function incidents(array $filters): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_system_incidents';
        $clauses = ['1=1'];
        $args = [];
        foreach (['status', 'severity', 'source'] as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $clauses[] = "{$field} = %s";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) .
            " ORDER BY FIELD(severity, 'critical', 'error', 'warning', 'info'), last_seen_at DESC, id DESC LIMIT 250";
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatIncident'], $rows) : [];
    }

    public function updateIncidentStatus(int $incidentId, string $status, int $actorUserId): void
    {
        global $wpdb;

        $data = ['status' => $status, 'updated_at' => current_time('mysql', true)];
        if ($status === 'acknowledged') {
            $data['acknowledged_by'] = $actorUserId;
            $data['acknowledged_at'] = current_time('mysql', true);
        }
        if ($status === 'resolved') {
            $data['resolved_by'] = $actorUserId;
            $data['resolved_at'] = current_time('mysql', true);
        }
        $updated = $wpdb->update($wpdb->prefix . 'rishe_system_incidents', $data, ['id' => $incidentId]);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update incident status.');
        }
    }
}
