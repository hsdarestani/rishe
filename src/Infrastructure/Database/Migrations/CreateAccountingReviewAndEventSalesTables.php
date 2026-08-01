<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateAccountingReviewAndEventSalesTables implements Migration
{
    public function id(): string
    {
        return '2026080102_create_accounting_review_event_sales_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $reviews = $wpdb->prefix . 'rishe_voucher_reviews';
        $events = $wpdb->prefix . 'rishe_sales_events';
        $sellers = $wpdb->prefix . 'rishe_sales_event_sellers';
        $sales = $wpdb->prefix . 'rishe_event_sales';
        $lines = $wpdb->prefix . 'rishe_event_sale_lines';

        dbDelta("CREATE TABLE {$reviews} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            voucher_id bigint(20) unsigned NOT NULL,
            review_status varchar(20) NOT NULL DEFAULT 'pending',
            source_type varchar(40) NOT NULL DEFAULT 'accounting',
            source_id varchar(191) NULL,
            title varchar(191) NULL,
            metadata_json longtext NULL,
            reviewed_by bigint(20) unsigned NULL,
            reviewed_at datetime NULL,
            review_note varchar(1000) NULL,
            reversal_voucher_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY voucher_id (voucher_id),
            KEY status_created (review_status, created_at),
            KEY source_reference (source_type, source_id),
            KEY reviewer (reviewed_by)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            name varchar(191) NOT NULL,
            location varchar(255) NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            notes text NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY status_dates (status, starts_at, ends_at),
            KEY warehouse_id (warehouse_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sellers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            assigned_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_user (event_id, user_id),
            KEY user_event (user_id, event_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sales} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            client_uuid char(36) NOT NULL,
            payload_hash char(64) NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            seller_user_id bigint(20) unsigned NOT NULL,
            wc_order_id bigint(20) unsigned NULL,
            rishe_order_id bigint(20) unsigned NULL,
            customer_name varchar(191) NOT NULL,
            customer_mobile varchar(20) NULL,
            subtotal_irr bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            total_irr bigint(20) unsigned NOT NULL,
            paid_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            cogs_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_method varchar(30) NOT NULL,
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'pending_configuration',
            status varchar(20) NOT NULL DEFAULT 'queued',
            occurred_at datetime NOT NULL,
            synced_at datetime NULL,
            error_message varchar(1000) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY client_uuid (client_uuid),
            UNIQUE KEY wc_order_id (wc_order_id),
            KEY event_status (event_id, status, occurred_at),
            KEY seller_status (seller_user_id, status, occurred_at),
            KEY accounting_voucher_id (accounting_voucher_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$lines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_sale_id bigint(20) unsigned NOT NULL,
            wc_product_id bigint(20) unsigned NOT NULL,
            rishe_product_id bigint(20) unsigned NULL,
            product_name varchar(191) NOT NULL,
            sku varchar(100) NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            line_total_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sale_product (event_sale_id, wc_product_id),
            KEY rishe_product_id (rishe_product_id),
            KEY wc_product_id (wc_product_id)
        ) {$charset};");

        $this->assertTablesExist([$reviews, $events, $sellers, $sales, $lines]);
    }

    /** @param list<string> $tables */
    private function assertTablesExist(array $tables): void
    {
        global $wpdb;

        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required table: ' . $table);
            }
        }
    }
}
