<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use Rishe\Inventory\Domain\Quantity;
use RuntimeException;

final class CreateInventoryTables implements Migration
{
    public function id(): string
    {
        return '2026071905_create_inventory_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        $products = $wpdb->prefix . 'rishe_products';
        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $reservations = $wpdb->prefix . 'rishe_stock_reservations';
        $allocations = $wpdb->prefix . 'rishe_stock_reservation_allocations';
        $movements = $wpdb->prefix . 'rishe_stock_movements';

        dbDelta("CREATE TABLE {$warehouses} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            type varchar(30) NOT NULL DEFAULT 'other',
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY active_type (is_active, type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$products} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sku varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            base_unit varchar(30) NOT NULL,
            quantity_scale int(10) unsigned NOT NULL DEFAULT " . Quantity::SCALE . ",
            inventory_method varchar(10) NOT NULL DEFAULT 'fifo',
            wc_product_id bigint(20) unsigned NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sku (sku),
            UNIQUE KEY wc_product_id (wc_product_id),
            KEY active_method (is_active, inventory_method)
        ) {$charset};");

        dbDelta("CREATE TABLE {$batches} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            batch_code varchar(100) NOT NULL,
            origin_batch_id bigint(20) unsigned NULL,
            received_at datetime NOT NULL,
            expiry_date date NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            quantity_on_hand bigint(20) NOT NULL,
            quantity_reserved bigint(20) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_warehouse_fifo (product_id, warehouse_id, status, received_at, id),
            KEY batch_code (batch_code),
            KEY expiry_date (expiry_date),
            KEY origin_batch_id (origin_batch_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$reservations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_key char(36) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            reference_type varchar(50) NOT NULL,
            reference_id varchar(191) NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            expires_at datetime NULL,
            committed_cogs_irr bigint(20) unsigned NULL,
            committed_at datetime NULL,
            released_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL,
            correlation_id varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_key (reservation_key),
            UNIQUE KEY reference_stock (reference_type, reference_id, product_id, warehouse_id),
            KEY active_expiry (status, expires_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$allocations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reservation_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_batch (reservation_id, batch_id),
            KEY batch_id (batch_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$movements} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            movement_id char(36) NOT NULL,
            type varchar(30) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            reservation_id bigint(20) unsigned NULL,
            transfer_group_id char(36) NULL,
            quantity_scaled bigint(20) NOT NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL,
            reference_type varchar(50) NULL,
            reference_id varchar(191) NULL,
            correlation_id varchar(64) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY movement_id (movement_id),
            KEY product_warehouse_created (product_id, warehouse_id, created_at, id),
            KEY batch_id (batch_id),
            KEY reservation_id (reservation_id),
            KEY transfer_group_id (transfer_group_id),
            KEY reference_lookup (reference_type, reference_id),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        foreach ([$warehouses, $products, $batches, $reservations, $allocations, $movements] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required inventory table: ' . $table);
            }
        }
    }
}
