<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateManufacturingTables implements Migration
{
    public function id(): string
    {
        return '2026071907_create_manufacturing_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $boms = $wpdb->prefix . 'rishe_boms';
        $components = $wpdb->prefix . 'rishe_bom_components';
        $orders = $wpdb->prefix . 'rishe_production_orders';
        $consumptions = $wpdb->prefix . 'rishe_production_consumptions';
        $outputs = $wpdb->prefix . 'rishe_production_outputs';

        dbDelta("CREATE TABLE {$boms} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            version int(10) unsigned NOT NULL,
            output_product_id bigint(20) unsigned NOT NULL,
            output_quantity_scaled bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            effective_from date NULL,
            effective_to date NULL,
            created_by bigint(20) unsigned NOT NULL,
            activated_by bigint(20) unsigned NULL,
            activated_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_version (code, version),
            KEY active_output (status, output_product_id),
            KEY effective_dates (effective_from, effective_to)
        ) {$charset};");

        dbDelta("CREATE TABLE {$components} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bom_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            component_type varchar(30) NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            waste_basis_points int(10) unsigned NOT NULL DEFAULT 0,
            sequence int(10) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY bom_product (bom_id, product_id),
            KEY bom_sequence (bom_id, sequence, id),
            KEY product_id (product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_key char(36) NOT NULL,
            bom_id bigint(20) unsigned NOT NULL,
            input_warehouse_id bigint(20) unsigned NOT NULL,
            output_warehouse_id bigint(20) unsigned NOT NULL,
            output_quantity_scaled bigint(20) NOT NULL,
            output_batch_code varchar(100) NOT NULL,
            output_expiry_date date NULL,
            labor_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            overhead_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            material_cost_irr bigint(20) unsigned NULL,
            waste_cost_irr bigint(20) unsigned NULL,
            total_cost_irr bigint(20) unsigned NULL,
            unit_cost_irr bigint(20) unsigned NULL,
            status varchar(20) NOT NULL DEFAULT 'processing',
            reference_type varchar(50) NOT NULL,
            reference_id varchar(191) NOT NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            completed_by bigint(20) unsigned NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_key (order_key),
            UNIQUE KEY production_reference (reference_type, reference_id),
            KEY bom_status (bom_id, status),
            KEY created_at (created_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$consumptions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            production_order_id bigint(20) unsigned NOT NULL,
            bom_component_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            standard_quantity_scaled bigint(20) NOT NULL,
            waste_quantity_scaled bigint(20) NOT NULL DEFAULT 0,
            total_quantity_scaled bigint(20) NOT NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL,
            material_cost_irr bigint(20) unsigned NOT NULL,
            waste_cost_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_component_batch (production_order_id, bom_component_id, batch_id),
            KEY production_order_id (production_order_id),
            KEY product_batch (product_id, batch_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$outputs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            production_order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL,
            total_cost_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY production_order_id (production_order_id),
            UNIQUE KEY batch_id (batch_id),
            KEY product_warehouse (product_id, warehouse_id)
        ) {$charset};");

        foreach ([$boms, $components, $orders, $consumptions, $outputs] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required manufacturing table: ' . $table);
            }
        }
    }
}
