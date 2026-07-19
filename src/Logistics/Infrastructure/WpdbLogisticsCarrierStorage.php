<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use RuntimeException;

trait WpdbLogisticsCarrierStorage
{
    public function upsertCarrier(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_logistics_carriers';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s FOR UPDATE",
            $data['code']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        if (is_array($existing)) {
            $updated = $wpdb->update($table, [
                'name' => $data['name'],
                'driver' => $data['driver'],
                'mode' => $data['mode'],
                'base_url' => $data['base_url'],
                'config_json' => $data['config_json'],
                'credentials_ciphertext' => $data['credentials_ciphertext'],
                'webhook_secret_ciphertext' => $data['webhook_secret_ciphertext'],
                'shipping_expense_subsidiary_ledger_id' => $data['shipping_expense_subsidiary_ledger_id'],
                'is_active' => 1,
                'updated_at' => $now,
            ], ['id' => $existing['id']], [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s',
            ], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('Unable to update carrier: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $id = $this->insert('rishe_logistics_carriers', [
            'public_id' => wp_generate_uuid4(),
            'code' => $data['code'],
            'name' => $data['name'],
            'driver' => $data['driver'],
            'mode' => $data['mode'],
            'base_url' => $data['base_url'],
            'config_json' => $data['config_json'],
            'credentials_ciphertext' => $data['credentials_ciphertext'],
            'webhook_secret_ciphertext' => $data['webhook_secret_ciphertext'],
            'shipping_expense_subsidiary_ledger_id' => $data['shipping_expense_subsidiary_ledger_id'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s'], 'carrier');

        return ['id' => $id, 'created' => true];
    }

    public function carrier(int $carrierId): ?array
    {
        $row = $this->row('rishe_logistics_carriers', $carrierId);

        return $row === null ? null : $this->formatCarrier($row);
    }

    public function carrierByCode(string $code): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_logistics_carriers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s",
            $code
        ), ARRAY_A);

        return is_array($row) ? $this->formatCarrier($row) : null;
    }

    public function carriers(array $filters): array
    {
        return array_map([$this, 'formatCarrier'], $this->simpleList(
            'rishe_logistics_carriers',
            $filters,
            ['is_active', 'code']
        ));
    }

    public function salesOrder(int $salesOrderId): ?array
    {
        $row = $this->row('rishe_sales_orders', $salesOrderId);
        if ($row === null) {
            return null;
        }
        foreach (['id', 'customer_id', 'warehouse_id', 'shipping_irr', 'total_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }
}
