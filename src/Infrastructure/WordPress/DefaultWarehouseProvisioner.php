<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\WooCommerce\Application\WooCommerceSyncService;

final class DefaultWarehouseProvisioner
{
    public function ensure(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_warehouses';
        $settings = get_option(WooCommerceSyncService::OPTION, []);
        $settings = is_array($settings) ? $settings : [];
        $configured = (int) ($settings['warehouse_id'] ?? get_option('rishe_woocommerce_warehouse_id', 0));

        if ($this->exists($table, $configured)) {
            $this->persist($settings, $configured);

            return $configured;
        }

        $warehouseId = (int) $wpdb->get_var(
            "SELECT id FROM {$table}
             WHERE is_active = 1
             ORDER BY CASE WHEN type = 'central' THEN 0 ELSE 1 END, id
             LIMIT 1"
        );

        if ($warehouseId < 1) {
            $warehouseId = $this->createDefault($table);
        }

        if ($warehouseId > 0) {
            $this->persist($settings, $warehouseId);
        }

        return $warehouseId;
    }

    private function createDefault(string $table): int
    {
        global $wpdb;

        $existing = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE code = %s LIMIT 1", 'CENTRAL')
        );
        if ($existing > 0) {
            $wpdb->update(
                $table,
                ['is_active' => 1, 'updated_at' => current_time('mysql', true)],
                ['id' => $existing],
                ['%d', '%s'],
                ['%d']
            );

            return $existing;
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $table,
            [
                'code' => 'CENTRAL',
                'name' => 'انبار مرکزی',
                'type' => 'central',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            error_log('[Rishe] Unable to create the default warehouse: ' . $wpdb->last_error);

            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $settings */
    private function persist(array $settings, int $warehouseId): void
    {
        if ((int) ($settings['warehouse_id'] ?? 0) !== $warehouseId) {
            $settings['warehouse_id'] = $warehouseId;
            update_option(WooCommerceSyncService::OPTION, $settings, false);
        }

        if ((int) get_option('rishe_woocommerce_warehouse_id', 0) !== $warehouseId) {
            update_option('rishe_woocommerce_warehouse_id', $warehouseId, false);
        }
    }

    private function exists(string $table, int $warehouseId): bool
    {
        global $wpdb;

        if ($warehouseId < 1) {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d AND is_active = 1", $warehouseId)
        ) === 1;
    }
}
