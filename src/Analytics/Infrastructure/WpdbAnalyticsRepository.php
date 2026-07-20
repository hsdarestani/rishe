<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure;

use JsonException;
use Rishe\Analytics\Application\AnalyticsRepository;
use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;
use RuntimeException;

final class WpdbAnalyticsRepository implements AnalyticsRepository
{
    use WpdbAnalyticsMasterData;
    use WpdbAnalyticsEvents;
    use WpdbAnalyticsReporting;

    private function table(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rishe_' . $suffix;
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('Analytics record not found.');
        }

        return $this->normalizeRow($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (preg_match('/(^id$|_id$|_irr$|_scaled$|_count$|_value$|_basis_points$|^is_)/', (string) $key)) {
                $row[$key] = (int) $value;
            }
            if (str_ends_with((string) $key, '_json') || $key === 'payload') {
                $decoded = json_decode((string) $value, true);
                $row[$key === 'payload_json' ? 'payload' : $key] = is_array($decoded) ? $decoded : [];
                if ($key === 'payload_json') {
                    unset($row[$key]);
                }
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode analytics JSON.', 0, $exception);
        }
    }

    private function now(): string
    {
        return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    }

    private function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** @param mixed $value */
    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array<string, mixed> $filters @return array{0:string,1:list<mixed>} */
    private function factFilter(array $filters, string $alias = 'f'): array
    {
        $where = ["{$alias}.fact_date BETWEEN %s AND %s"];
        $args = [(string) ($filters['from'] ?? gmdate('Y-m-01')), (string) ($filters['to'] ?? gmdate('Y-m-d'))];
        foreach (['sales_channel', 'product_line', 'province', 'city'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "{$alias}.{$field} = %s";
                $args[] = (string) $filters[$field];
            }
        }

        return [implode(' AND ', $where), $args];
    }

    /** @param list<mixed> $args */
    private function prepare(string $sql, array $args): string
    {
        global $wpdb;

        return $args === [] ? $sql : (string) $wpdb->prepare($sql, ...$args);
    }

    private function assertInserted(int|false $result, string $message): void
    {
        if ($result === false) {
            global $wpdb;
            throw new RuntimeException($message . ($wpdb->last_error !== '' ? ': ' . $wpdb->last_error : ''));
        }
    }

    /** @return array<string, mixed> */
    private function sourceById(int $id): array
    {
        return $this->row($this->table('analytics_sources'), $id);
    }

    /** @return array<string, mixed> */
    private function campaignById(int $id): array
    {
        return $this->row($this->table('analytics_campaigns'), $id);
    }

    /** @return array<string, mixed> */
    private function targetById(int $id): array
    {
        return $this->row($this->table('analytics_targets'), $id);
    }
}
