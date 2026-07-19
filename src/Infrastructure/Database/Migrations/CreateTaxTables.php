<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateTaxTables implements Migration
{
    public function id(): string
    {
        return '2026071919_create_tax_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $profiles = $wpdb->prefix . 'rishe_tax_profiles';
        $mappings = $wpdb->prefix . 'rishe_tax_product_mappings';
        $sequences = $wpdb->prefix . 'rishe_tax_sequences';
        $invoices = $wpdb->prefix . 'rishe_tax_invoices';
        $lines = $wpdb->prefix . 'rishe_tax_invoice_lines';
        $payments = $wpdb->prefix . 'rishe_tax_invoice_payments';
        $submissions = $wpdb->prefix . 'rishe_tax_submissions';
        $events = $wpdb->prefix . 'rishe_tax_status_events';

        dbDelta("CREATE TABLE {$profiles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            taxpayer_type tinyint unsigned NOT NULL,
            national_id varchar(30) NOT NULL,
            economic_code varchar(30) NOT NULL,
            fiscal_memory_id char(6) NOT NULL,
            branch_code varchar(20) NULL,
            default_invoice_type tinyint unsigned NOT NULL DEFAULT 1,
            default_pattern tinyint unsigned NOT NULL DEFAULT 1,
            gateway_type varchar(30) NOT NULL,
            gateway_config_json longtext NOT NULL,
            credentials_ciphertext longtext NOT NULL,
            private_key_ciphertext longtext NOT NULL,
            certificate_pem longtext NULL,
            key_id varchar(191) NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            UNIQUE KEY fiscal_memory_id (fiscal_memory_id),
            KEY active_name (is_active, name)
        ) {$charset};");

        dbDelta("CREATE TABLE {$mappings} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            tax_product_id varchar(50) NOT NULL,
            measurement_unit varchar(30) NOT NULL,
            vat_rate_basis_points int unsigned NOT NULL,
            description varchar(191) NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_product (profile_id, product_id),
            KEY tax_product_id (tax_product_id),
            KEY active_mapping (profile_id, is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sequences} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            next_serial bigint(20) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_year (profile_id, fiscal_year)
        ) {$charset};");

        dbDelta("CREATE TABLE {$invoices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            profile_id bigint(20) unsigned NOT NULL,
            sales_order_id bigint(20) unsigned NULL,
            source_invoice_id bigint(20) unsigned NULL,
            derived_invoice_id bigint(20) unsigned NULL,
            subject varchar(30) NOT NULL,
            subject_code tinyint unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'draft',
            invoice_type tinyint unsigned NOT NULL,
            invoice_pattern tinyint unsigned NOT NULL,
            settlement_method tinyint unsigned NOT NULL,
            fiscal_year smallint unsigned NULL,
            internal_serial bigint(20) unsigned NULL,
            tax_number char(22) NULL,
            source_tax_number char(22) NULL,
            buyer_type tinyint unsigned NOT NULL,
            buyer_name varchar(191) NULL,
            buyer_national_id varchar(30) NULL,
            buyer_economic_code varchar(30) NULL,
            buyer_postal_code varchar(20) NULL,
            buyer_branch_code varchar(20) NULL,
            seller_national_id varchar(30) NOT NULL,
            seller_economic_code varchar(30) NOT NULL,
            seller_branch_code varchar(20) NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            net_irr bigint(20) unsigned NOT NULL,
            vat_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            other_duty_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            total_irr bigint(20) unsigned NOT NULL,
            cash_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            credit_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            idempotency_key varchar(100) NOT NULL,
            source_hash char(64) NOT NULL,
            payload_json longtext NULL,
            payload_sha256 char(64) NULL,
            signature longtext NULL,
            reference_number varchar(191) NULL,
            remote_uid varchar(191) NULL,
            submission_attempts int unsigned NOT NULL DEFAULT 0,
            last_error_code varchar(100) NULL,
            last_error_message varchar(1000) NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            frozen_by bigint(20) unsigned NULL,
            issued_at datetime NULL,
            frozen_at datetime NULL,
            submitted_at datetime NULL,
            accepted_at datetime NULL,
            rejected_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY tax_number (tax_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY profile_serial (profile_id, fiscal_year, internal_serial),
            KEY profile_status (profile_id, status, id),
            KEY sales_order_id (sales_order_id),
            KEY source_invoice_id (source_invoice_id),
            KEY reference_number (reference_number),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$lines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tax_invoice_id bigint(20) unsigned NOT NULL,
            sales_order_line_id bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NULL,
            tax_product_id varchar(50) NOT NULL,
            description varchar(191) NOT NULL,
            measurement_unit varchar(30) NOT NULL,
            quantity_scaled bigint(20) NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            net_irr bigint(20) unsigned NOT NULL,
            vat_rate_basis_points int unsigned NOT NULL,
            vat_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            other_duty_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            total_irr bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY invoice_id (tax_invoice_id),
            KEY product_id (product_id),
            KEY tax_product_id (tax_product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tax_invoice_id bigint(20) unsigned NOT NULL,
            provider varchar(50) NULL,
            external_payment_id varchar(191) NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            captured_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY invoice_id (tax_invoice_id),
            KEY external_payment_id (external_payment_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$submissions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            tax_invoice_id bigint(20) unsigned NOT NULL,
            attempt_number int unsigned NOT NULL,
            request_hash char(64) NOT NULL,
            response_hash char(64) NOT NULL,
            reference_number varchar(191) NULL,
            remote_uid varchar(191) NULL,
            status varchar(30) NOT NULL,
            error_code varchar(100) NULL,
            error_message varchar(1000) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY invoice_attempt (tax_invoice_id, attempt_number),
            KEY reference_number (reference_number),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tax_invoice_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL,
            source varchar(30) NOT NULL,
            reference_number varchar(191) NULL,
            payload_hash char(64) NOT NULL,
            message varchar(1000) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_payload (tax_invoice_id, payload_hash),
            KEY invoice_created (tax_invoice_id, created_at),
            KEY status (status)
        ) {$charset};");

        foreach ([$profiles, $mappings, $sequences, $invoices, $lines, $payments, $submissions, $events] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required tax table: ' . $table);
            }
        }
    }
}
