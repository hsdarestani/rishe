<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateLogisticsTables implements Migration
{
    public function id(): string
    {
        return '2026071917_create_logistics_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $carriers = $wpdb->prefix . 'rishe_logistics_carriers';
        $shipments = $wpdb->prefix . 'rishe_shipments';
        $packages = $wpdb->prefix . 'rishe_shipment_packages';
        $quotes = $wpdb->prefix . 'rishe_shipment_quotes';
        $events = $wpdb->prefix . 'rishe_shipment_tracking_events';
        $costs = $wpdb->prefix . 'rishe_shipment_costs';
        $settlements = $wpdb->prefix . 'rishe_logistics_settlements';

        dbDelta("CREATE TABLE {$carriers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(50) NOT NULL,
            name varchar(191) NOT NULL,
            driver varchar(50) NOT NULL DEFAULT 'http_json',
            mode varchar(20) NOT NULL DEFAULT 'sandbox',
            base_url varchar(500) NOT NULL,
            config_json longtext NOT NULL,
            credentials_ciphertext longtext NOT NULL,
            webhook_secret_ciphertext longtext NOT NULL,
            shipping_expense_subsidiary_ledger_id bigint(20) unsigned NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY active_code (is_active, code)
        ) {$charset};");

        dbDelta("CREATE TABLE {$shipments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            sales_order_id bigint(20) unsigned NULL,
            carrier_id bigint(20) unsigned NULL,
            selected_quote_id bigint(20) unsigned NULL,
            status varchar(30) NOT NULL DEFAULT 'draft',
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            service_code varchar(100) NULL,
            external_shipment_id varchar(191) NULL,
            tracking_number varchar(191) NULL,
            label_url varchar(1000) NULL,
            sender_json longtext NOT NULL,
            recipient_json longtext NOT NULL,
            declared_value_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            charged_shipping_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            quoted_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            actual_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            settled_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            cost_variance_irr bigint(20) NOT NULL DEFAULT 0,
            cod_amount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            package_count int unsigned NOT NULL,
            total_weight_grams bigint(20) unsigned NOT NULL,
            volumetric_weight_grams bigint(20) unsigned NOT NULL,
            notes text NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            booked_at datetime NULL,
            in_transit_at datetime NULL,
            delivered_at datetime NULL,
            cancelled_at datetime NULL,
            returned_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY carrier_external (carrier_id, external_shipment_id),
            UNIQUE KEY carrier_tracking (carrier_id, tracking_number),
            KEY sales_order_id (sales_order_id),
            KEY status_created (status, created_at),
            KEY carrier_status (carrier_id, status, id),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$packages} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) unsigned NOT NULL,
            sequence_no int unsigned NOT NULL,
            weight_grams int unsigned NOT NULL,
            length_mm int unsigned NOT NULL,
            width_mm int unsigned NOT NULL,
            height_mm int unsigned NOT NULL,
            quantity int unsigned NOT NULL DEFAULT 1,
            total_weight_grams bigint(20) unsigned NOT NULL,
            volumetric_weight_grams bigint(20) unsigned NOT NULL,
            contents varchar(500) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY shipment_sequence (shipment_id, sequence_no),
            KEY shipment_id (shipment_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$quotes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            shipment_id bigint(20) unsigned NOT NULL,
            carrier_id bigint(20) unsigned NOT NULL,
            service_code varchar(100) NOT NULL,
            service_name varchar(191) NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            currency char(3) NOT NULL DEFAULT 'IRR',
            estimated_days int unsigned NULL,
            expires_at datetime NULL,
            raw_hash char(64) NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY quote_identity (shipment_id, carrier_id, service_code, raw_hash),
            KEY shipment_amount (shipment_id, amount_irr),
            KEY carrier_id (carrier_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            shipment_id bigint(20) unsigned NOT NULL,
            carrier_id bigint(20) unsigned NOT NULL,
            external_event_id varchar(191) NOT NULL,
            status varchar(30) NOT NULL,
            occurred_at datetime NOT NULL,
            description varchar(500) NULL,
            location varchar(191) NULL,
            raw_hash char(64) NULL,
            event_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY event_hash (event_hash),
            UNIQUE KEY carrier_event (carrier_id, external_event_id),
            KEY shipment_occurred (shipment_id, occurred_at, id),
            KEY status_occurred (status, occurred_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$costs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            shipment_id bigint(20) unsigned NOT NULL,
            carrier_id bigint(20) unsigned NOT NULL,
            cost_type varchar(50) NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            external_cost_id varchar(191) NOT NULL,
            invoice_reference varchar(191) NULL,
            incurred_at datetime NOT NULL,
            description varchar(500) NULL,
            raw_hash char(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY carrier_external_cost (carrier_id, external_cost_id),
            KEY shipment_incurred (shipment_id, incurred_at, id),
            KEY invoice_reference (invoice_reference)
        ) {$charset};");

        dbDelta("CREATE TABLE {$settlements} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            shipment_id bigint(20) unsigned NOT NULL,
            treasury_transaction_id bigint(20) unsigned NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'pending_configuration',
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_voucher_number bigint(20) unsigned NULL,
            settled_by bigint(20) unsigned NOT NULL,
            settled_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY treasury_transaction_id (treasury_transaction_id),
            KEY shipment_settled (shipment_id, settled_at, id)
        ) {$charset};");

        foreach ([$carriers, $shipments, $packages, $quotes, $events, $costs, $settlements] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required logistics table: ' . $table);
            }
        }
    }
}
