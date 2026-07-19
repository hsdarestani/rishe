<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateB2BTables implements Migration
{
    public function id(): string
    {
        return '2026071915_create_b2b_consignment_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sequences = $wpdb->prefix . 'rishe_b2b_sequences';
        $accounts = $wpdb->prefix . 'rishe_b2b_accounts';
        $dispatches = $wpdb->prefix . 'rishe_consignment_dispatches';
        $dispatchLines = $wpdb->prefix . 'rishe_consignment_dispatch_lines';
        $returns = $wpdb->prefix . 'rishe_consignment_returns';
        $returnLines = $wpdb->prefix . 'rishe_consignment_return_lines';
        $reports = $wpdb->prefix . 'rishe_agent_sales_reports';
        $reportLines = $wpdb->prefix . 'rishe_agent_sales_report_lines';
        $allocations = $wpdb->prefix . 'rishe_consignment_sale_allocations';
        $ledger = $wpdb->prefix . 'rishe_b2b_ledger';
        $settlements = $wpdb->prefix . 'rishe_b2b_settlements';

        dbDelta("CREATE TABLE {$sequences} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sequence_type varchar(30) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            next_number bigint(20) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY type_year (sequence_type, fiscal_year)
        ) {$charset};");

        dbDelta("CREATE TABLE {$accounts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            account_type varchar(20) NOT NULL,
            consignment_warehouse_id bigint(20) unsigned NOT NULL,
            credit_limit_irr bigint(20) unsigned NOT NULL,
            current_receivable_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            commission_rate_bps smallint unsigned NOT NULL DEFAULT 0,
            settlement_terms_days int unsigned NOT NULL DEFAULT 0,
            receivable_subsidiary_ledger_id bigint(20) unsigned NULL,
            floating_detail_id bigint(20) unsigned NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY customer_id (customer_id),
            UNIQUE KEY code (code),
            KEY type_status (account_type, status),
            KEY warehouse_id (consignment_warehouse_id),
            KEY receivable_ledger (receivable_subsidiary_ledger_id),
            KEY floating_detail (floating_detail_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$dispatches} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            document_number bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            source_warehouse_id bigint(20) unsigned NOT NULL,
            destination_warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'posting',
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            reference varchar(191) NULL,
            notes text NULL,
            correlation_id varchar(64) NULL,
            dispatched_by bigint(20) unsigned NOT NULL,
            dispatched_at datetime NOT NULL,
            created_at datetime NOT NULL,
            posted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_document (fiscal_year, document_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY account_status (account_id, status, id),
            KEY source_date (source_warehouse_id, dispatched_at),
            KEY destination_date (destination_warehouse_id, dispatched_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$dispatchLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dispatch_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL,
            sku varchar(100) NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            sold_quantity_scaled bigint(20) unsigned NOT NULL DEFAULT 0,
            returned_quantity_scaled bigint(20) unsigned NOT NULL DEFAULT 0,
            transfer_group_id char(36) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dispatch_product (dispatch_id, product_id),
            UNIQUE KEY transfer_group_id (transfer_group_id),
            KEY account_product (product_id, dispatch_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$returns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            document_number bigint(20) unsigned NOT NULL,
            dispatch_id bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            source_warehouse_id bigint(20) unsigned NOT NULL,
            destination_warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'posting',
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            notes text NULL,
            correlation_id varchar(64) NULL,
            returned_by bigint(20) unsigned NOT NULL,
            returned_at datetime NOT NULL,
            created_at datetime NOT NULL,
            posted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_document (fiscal_year, document_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY dispatch_id (dispatch_id),
            KEY account_date (account_id, returned_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$returnLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            return_id bigint(20) unsigned NOT NULL,
            dispatch_line_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            transfer_group_id char(36) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY return_dispatch_line (return_id, dispatch_line_id),
            UNIQUE KEY transfer_group_id (transfer_group_id),
            KEY dispatch_line_id (dispatch_line_id),
            KEY product_id (product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$reports} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            document_number bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'posting',
            external_reference varchar(191) NULL,
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            commission_irr bigint(20) unsigned NOT NULL,
            receivable_irr bigint(20) unsigned NOT NULL,
            cogs_irr bigint(20) unsigned NULL,
            due_date date NULL,
            notes text NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'pending_configuration',
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_voucher_number bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            reported_by bigint(20) unsigned NOT NULL,
            reported_at datetime NOT NULL,
            created_at datetime NOT NULL,
            posted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_document (fiscal_year, document_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY account_external (account_id, external_reference),
            KEY account_date (account_id, reported_at),
            KEY status_date (status, reported_at),
            KEY accounting_status (accounting_status),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$reportLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sales_report_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL,
            sku varchar(100) NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            commission_rate_bps smallint unsigned NOT NULL,
            commission_irr bigint(20) unsigned NOT NULL,
            receivable_irr bigint(20) unsigned NOT NULL,
            reservation_id bigint(20) unsigned NULL,
            cogs_irr bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY report_product (sales_report_id, product_id),
            UNIQUE KEY reservation_id (reservation_id),
            KEY product_id (product_id),
            KEY report_id (sales_report_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$allocations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sales_report_line_id bigint(20) unsigned NOT NULL,
            dispatch_line_id bigint(20) unsigned NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY report_dispatch_line (sales_report_line_id, dispatch_line_id),
            KEY dispatch_line_id (dispatch_line_id),
            KEY report_line_id (sales_report_line_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$ledger} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            sales_report_id bigint(20) unsigned NULL,
            settlement_id bigint(20) unsigned NULL,
            entry_type varchar(30) NOT NULL,
            charge_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            due_date date NULL,
            description varchar(500) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY report_charge (sales_report_id, entry_type),
            UNIQUE KEY settlement_entry (settlement_id, entry_type),
            KEY account_date (account_id, created_at, id),
            KEY due_date (account_id, due_date)
        ) {$charset};");

        dbDelta("CREATE TABLE {$settlements} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
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
            KEY account_date (account_id, settled_at)
        ) {$charset};");

        $required = [
            $sequences,
            $accounts,
            $dispatches,
            $dispatchLines,
            $returns,
            $returnLines,
            $reports,
            $reportLines,
            $allocations,
            $ledger,
            $settlements,
        ];
        foreach ($required as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required B2B table: ' . $table);
            }
        }
    }
}
