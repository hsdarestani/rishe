<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateSalesCrmTables implements Migration
{
    public function id(): string
    {
        return '2026071909_create_sales_crm_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $customers = $wpdb->prefix . 'rishe_customers';
        $customerChannels = $wpdb->prefix . 'rishe_customer_channels';
        $prices = $wpdb->prefix . 'rishe_channel_prices';
        $promotions = $wpdb->prefix . 'rishe_promotions';
        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $payments = $wpdb->prefix . 'rishe_sales_payments';
        $redemptions = $wpdb->prefix . 'rishe_promotion_redemptions';
        $loyalty = $wpdb->prefix . 'rishe_loyalty_ledger';
        $history = $wpdb->prefix . 'rishe_order_status_history';

        dbDelta("CREATE TABLE {$customers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_key char(36) NOT NULL,
            mobile_normalized varchar(20) NOT NULL,
            first_name varchar(100) NULL,
            last_name varchar(100) NULL,
            email varchar(191) NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            loyalty_balance bigint(20) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_key (customer_key),
            UNIQUE KEY mobile_normalized (mobile_normalized),
            KEY email (email),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$customerChannels} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            channel varchar(30) NOT NULL,
            external_customer_id varchar(191) NOT NULL,
            metadata_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_external (channel, external_customer_id),
            KEY customer_id (customer_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            channel varchar(30) NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            starts_at datetime NULL,
            ends_at datetime NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active_price (product_id, channel, is_active, starts_at, ends_at),
            KEY channel (channel)
        ) {$charset};");

        dbDelta("CREATE TABLE {$promotions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            promotion_key char(36) NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            discount_type varchar(20) NOT NULL,
            value bigint(20) unsigned NOT NULL,
            max_discount_irr bigint(20) unsigned NULL,
            min_order_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            channel varchar(30) NULL,
            starts_at datetime NULL,
            ends_at datetime NULL,
            usage_limit bigint(20) unsigned NULL,
            per_customer_limit bigint(20) unsigned NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY promotion_key (promotion_key),
            UNIQUE KEY code (code),
            KEY active_window (is_active, starts_at, ends_at),
            KEY channel (channel)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_key char(36) NOT NULL,
            channel varchar(30) NOT NULL,
            external_order_id varchar(191) NULL,
            idempotency_key varchar(100) NULL,
            source_hash char(64) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending_payment',
            payment_status varchar(30) NOT NULL DEFAULT 'unpaid',
            fulfillment_status varchar(30) NOT NULL DEFAULT 'unfulfilled',
            currency char(3) NOT NULL DEFAULT 'IRR',
            gross_irr bigint(20) unsigned NOT NULL,
            line_discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            subtotal_irr bigint(20) unsigned NOT NULL,
            promotion_discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            loyalty_discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            shipping_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            total_irr bigint(20) unsigned NOT NULL,
            cogs_irr bigint(20) unsigned NULL,
            loyalty_points_redeemed bigint(20) unsigned NOT NULL DEFAULT 0,
            loyalty_points_earned bigint(20) unsigned NOT NULL DEFAULT 0,
            promotion_id bigint(20) unsigned NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'not_applicable',
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_voucher_number bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            paid_at datetime NULL,
            completed_at datetime NULL,
            cancelled_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_key (order_key),
            UNIQUE KEY channel_external (channel, external_order_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY customer_created (customer_id, created_at),
            KEY status_created (status, created_at),
            KEY channel_created (channel, created_at),
            KEY correlation_id (correlation_id),
            KEY accounting_status (accounting_status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$lines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            sku_snapshot varchar(100) NOT NULL,
            name_snapshot varchar(191) NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            line_discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            net_irr bigint(20) unsigned NOT NULL,
            reservation_id bigint(20) unsigned NULL,
            cogs_irr bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY reservation_id (reservation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_key char(36) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            provider varchar(50) NOT NULL,
            external_payment_id varchar(191) NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL,
            captured_at datetime NOT NULL,
            raw_hash char(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY payment_key (payment_key),
            UNIQUE KEY provider_external (provider, external_payment_id),
            KEY order_id (order_id),
            KEY captured_at (captured_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$redemptions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            promotion_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY promotion_order (promotion_id, order_id),
            KEY promotion_customer (promotion_id, customer_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$loyalty} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_key char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NULL,
            entry_type varchar(30) NOT NULL,
            points bigint(20) NOT NULL,
            balance_after bigint(20) NOT NULL,
            description varchar(255) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY entry_key (entry_key),
            KEY customer_created (customer_id, created_at),
            KEY order_id (order_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$history} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            from_status varchar(30) NULL,
            to_status varchar(30) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            reason varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_created (order_id, created_at)
        ) {$charset};");

        $tables = [
            $customers, $customerChannels, $prices, $promotions, $orders,
            $lines, $payments, $redemptions, $loyalty, $history,
        ];
        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required sales/CRM table: ' . $table);
            }
        }
    }
}
