<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use RuntimeException;

trait WpdbLogisticsStorageHelpers
{
    /** @return array<string, mixed>|null */
    private function row(string $suffix, int $id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert(string $suffix, array $data, array $formats, string $entity): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . $suffix, $data, $formats);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create ' . $entity . ': ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowed
     * @return list<array<string, mixed>>
     */
    private function simpleList(string $suffix, array $filters, array $allowed): array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $clauses = ['1=1'];
        $args = [];
        foreach ($allowed as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $format = str_ends_with($field, '_id') || $field === 'is_active' ? '%d' : '%s';
            $clauses[] = "{$field} = {$format}";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT 250';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatCarrier(array $row): array
    {
        foreach (['id', 'shipping_expense_subsidiary_ledger_id', 'created_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['is_active'] = (bool) $row['is_active'];
        $config = json_decode((string) $row['config_json'], true);
        $row['config'] = is_array($config) ? $config : [];

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatShipment(array $row): array
    {
        $integerFields = [
            'id', 'sales_order_id', 'carrier_id', 'selected_quote_id', 'declared_value_irr',
            'charged_shipping_irr', 'quoted_cost_irr', 'actual_cost_irr', 'settled_cost_irr',
            'cost_variance_irr', 'cod_amount_irr', 'package_count', 'total_weight_grams',
            'volumetric_weight_grams', 'created_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        foreach (['sender_json' => 'sender', 'recipient_json' => 'recipient'] as $source => $target) {
            $decoded = json_decode((string) $row[$source], true);
            $row[$target] = is_array($decoded) ? $decoded : [];
            unset($row[$source]);
        }
        $row['unsettled_cost_irr'] = (int) $row['actual_cost_irr'] - (int) $row['settled_cost_irr'];

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function castIntegers(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }
}
