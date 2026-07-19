<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateProcurementTables implements Migration
{
    public function id(): string
    {
        return '2026071913_create_procurement_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sequences = $wpdb->prefix . 'rishe_purchase_sequences';
        $suppliers = $wpdb->prefix . 'rishe_suppliers';
        $orders = $wpdb->prefix . 'rishe_purchase_orders';
        $orderLines = $wpdb->prefix . 'rishe_purchase_order_lines';
        $receipts = $wpdb->prefix . 'rishe_purchase_receipts';
        $receiptLines = $wpdb->prefix . 'rishe_purchase_receipt_lines';
        $landedCosts = $wpdb->prefix . 'rishe_purchase_landed_costs';
        $supplierLedger = $wpdb->prefix . 'rishe_supplier_ledger';
        $payments = $wpdb->prefix . 'rishe_purchase_payments';

        dbDelta("CREATE TABLE {$sequences} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sequence_type varchar(30) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            next_number bigint(20) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY type_year (sequence_type, fiscal_year)
        ) {$charset};");

        dbDelta("CREATE TABLE {$suppliers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            mobile varchar(20) NULL,
            email varchar(191) NULL,
            national_id varchar(30) NULL,
            economic_code varchar(30) NULL,
            tax_id varchar(50) NULL,
            iban varchar(34) NULL,
            payment_terms_days int unsigned NOT NULL DEFAULT 0,
            credit_limit_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            payable_subsidiary_ledger_id bigint(20) unsigned NULL,
            floating_detail_id bigint(20) unsigned NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            UNIQUE KEY national_id (national_id),
            KEY active_name (is_active, name),
            KEY payable_ledger (payable_subsidiary_ledger_id),
            KEY floating_detail (floating_detail_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            document_number bigint(20) unsigned NULL,
            supplier_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'draft',
            external_reference varchar(191) NULL,
            idempotency_key varchar(100) NULL,
            payload_hash char(64) NOT NULL,
            expected_at date NULL,
            notes text NULL,
            merchandise_gross_irr bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            merchandise_net_irr bigint(20) unsigned NOT NULL,
            tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            estimated_landed_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            estimated_total_irr bigint(20) unsigned NOT NULL,
            payment_terms_days int unsigned NOT NULL DEFAULT 0,
            received_merchandise_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            received_tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            received_landed_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            received_liability_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            paid_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            approved_by bigint(20) unsigned NULL,
            cancelled_by bigint(20) unsigned NULL,
            approved_at datetime NULL,
            cancelled_at datetime NULL,
            cancellation_reason varchar(500) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_document (fiscal_year, document_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY supplier_external (supplier_id, external_reference),
            KEY supplier_status (supplier_id, status, id),
            KEY warehouse_status (warehouse_id, status, id),
            KEY expected_at (status, expected_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orderLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL,
            sku varchar(100) NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            unit_price_irr bigint(20) unsigned NOT NULL,
            gross_irr bigint(20) unsigned NOT NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            inventory_value_irr bigint(20) unsigned NOT NULL,
            tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            line_total_irr bigint(20) unsigned NOT NULL,
            received_quantity_scaled bigint(20) unsigned NOT NULL DEFAULT 0,
            received_inventory_value_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            received_tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            description varchar(500) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_product (purchase_order_id, product_id),
            KEY product_id (product_id),
            KEY order_id (purchase_order_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$receipts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint unsigned NOT NULL,
            document_number bigint(20) unsigned NOT NULL,
            purchase_order_id bigint(20) unsigned NOT NULL,
            supplier_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'posting',
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            merchandise_value_irr bigint(20) unsigned NOT NULL,
            tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            landed_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            liability_irr bigint(20) unsigned NOT NULL,
            received_at datetime NOT NULL,
            due_date date NOT NULL,
            reference varchar(191) NULL,
            notes text NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'pending_configuration',
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_voucher_number bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            received_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            posted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_document (fiscal_year, document_number),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY purchase_order_id (purchase_order_id),
            KEY supplier_due (supplier_id, due_date, id),
            KEY warehouse_date (warehouse_id, received_at),
            KEY accounting_status (accounting_status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$receiptLines} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_receipt_id bigint(20) unsigned NOT NULL,
            purchase_order_line_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL,
            quantity_scaled bigint(20) unsigned NOT NULL,
            merchandise_value_irr bigint(20) unsigned NOT NULL,
            tax_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            landed_cost_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            liability_irr bigint(20) unsigned NOT NULL,
            unit_cost_irr bigint(20) unsigned NOT NULL,
            batch_code varchar(100) NOT NULL,
            expiry_date date NULL,
            inventory_batch_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY receipt_order_line (purchase_receipt_id, purchase_order_line_id),
            UNIQUE KEY inventory_batch_id (inventory_batch_id),
            KEY order_line_id (purchase_order_line_id),
            KEY product_id (product_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$landedCosts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purchase_receipt_id bigint(20) unsigned NOT NULL,
            cost_type varchar(50) NOT NULL,
            description varchar(500) NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            allocation_basis varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY receipt_id (purchase_receipt_id),
            KEY cost_type (cost_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$supplierLedger} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            supplier_id bigint(20) unsigned NOT NULL,
            purchase_order_id bigint(20) unsigned NULL,
            purchase_receipt_id bigint(20) unsigned NULL,
            purchase_payment_id bigint(20) unsigned NULL,
            entry_type varchar(30) NOT NULL,
            charge_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            due_date date NULL,
            description varchar(500) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY receipt_charge (purchase_receipt_id, entry_type),
            UNIQUE KEY payment_entry (purchase_payment_id, entry_type),
            KEY supplier_date (supplier_id, created_at, id),
            KEY purchase_order_id (purchase_order_id),
            KEY due_date (supplier_id, due_date)
        ) {$charset};");

        dbDelta("CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            purchase_order_id bigint(20) unsigned NOT NULL,
            supplier_id bigint(20) unsigned NOT NULL,
            treasury_transaction_id bigint(20) unsigned NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            accounting_status varchar(30) NOT NULL DEFAULT 'pending_configuration',
            accounting_voucher_id bigint(20) unsigned NULL,
            accounting_voucher_number bigint(20) unsigned NULL,
            paid_by bigint(20) unsigned NOT NULL,
            paid_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY treasury_transaction_id (treasury_transaction_id),
            KEY purchase_order_id (purchase_order_id),
            KEY supplier_date (supplier_id, paid_at)
        ) {$charset};");

        $required = [
            $sequences,
            $suppliers,
            $orders,
            $orderLines,
            $receipts,
            $receiptLines,
            $landedCosts,
            $supplierLedger,
            $payments,
        ];
        foreach ($required as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required procurement table: ' . $table);
            }
        }
    }
}
