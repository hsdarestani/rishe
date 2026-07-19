<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

trait WpdbOperationsStorageHelpers
{
    private function findJob(int $jobId, bool $forUpdate): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_operation_jobs';
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d{$suffix}",
            $jobId
        ), ARRAY_A);

        return is_array($row) ? $this->formatJob($row) : null;
    }

    private function count(string $table, string $where): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatJob(array $row): array
    {
        foreach (['id', 'priority', 'attempts', 'max_attempts', 'created_by'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['payload'] = $this->decode($row['payload_json']);
        $row['result'] = $row['result_json'] === null ? null : $this->decode($row['result_json']);
        unset($row['payload_json'], $row['result_json'], $row['lock_token']);

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatJobEvent(array $row): array
    {
        foreach (['id', 'job_id', 'actor_user_id'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['context'] = $this->decode($row['context_json']);
        unset($row['context_json']);

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatIncident(array $row): array
    {
        foreach (['id', 'occurrences', 'acknowledged_by', 'resolved_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['context'] = $this->decode($row['context_json']);
        unset($row['context_json']);

        return $row;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
