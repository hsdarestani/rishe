<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateTreasuryTables implements Migration
{
    public function id(): string
    {
        return '2026071911_create_treasury_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $accounts = $wpdb->prefix . 'rishe_treasury_accounts';
        $providers = $wpdb->prefix . 'rishe_treasury_providers';
        $links = $wpdb->prefix . 'rishe_payment_links';
        $transactions = $wpdb->prefix . 'rishe_treasury_transactions';
        $matches = $wpdb->prefix . 'rishe_reconciliation_matches';
        $settlements = $wpdb->prefix . 'rishe_treasury_settlements';

        dbDelta("CREATE TABLE {$accounts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            type varchar(20) NOT NULL,
            bank_name varchar(100) NULL,
            iban varchar(34) NULL,
            account_number varchar(50) NULL,
            card_number varchar(30) NULL,
            currency char(3) NOT NULL DEFAULT 'IRR',
            subsidiary_ledger_id bigint(20) unsigned NULL,
            floating_detail_id bigint(20) unsigned NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            UNIQUE KEY iban (iban),
            KEY active_type (is_active, type),
            KEY subsidiary_ledger_id (subsidiary_ledger_id),
            KEY floating_detail_id (floating_detail_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$providers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(100) NOT NULL,
            name varchar(191) NOT NULL,
            adapter varchar(50) NOT NULL,
            treasury_account_id bigint(20) unsigned NOT NULL,
            config_json longtext NOT NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY active_adapter (is_active, adapter),
            KEY treasury_account_id (treasury_account_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            provider_id bigint(20) unsigned NOT NULL,
            treasury_account_id bigint(20) unsigned NOT NULL,
            sales_order_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'creating',
            idempotency_key varchar(100) NOT NULL,
            payload_hash char(64) NOT NULL,
            provider_link_id varchar(191) NULL,
            payment_url text NULL,
            reference_type varchar(50) NULL,
            reference_id varchar(191) NULL,
            description varchar(500) NULL,
            expires_at datetime NULL,
            paid_transaction_id bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            activated_at datetime NULL,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY provider_reference (provider_id, provider_link_id),
            KEY sales_order_id (sales_order_id),
            KEY customer_id (customer_id),
            KEY account_status (treasury_account_id, status),
            KEY expires_at (status, expires_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$transactions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            treasury_account_id bigint(20) unsigned NOT NULL,
            direction varchar(10) NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            transaction_at datetime NOT NULL,
            value_date date NULL,
            external_transaction_id varchar(191) NOT NULL,
            reference varchar(191) NULL,
            counterparty_name varchar(191) NULL,
            counterparty_iban varchar(34) NULL,
            description varchar(500) NULL,
            source varchar(50) NOT NULL,
            raw_hash char(64) NULL,
            correlation_id varchar(64) NULL,
            imported_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY account_external_transaction (treasury_account_id, external_transaction_id),
            KEY account_date (treasury_account_id, transaction_at, id),
            KEY direction_date (direction, transaction_at),
            KEY reference (reference),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$matches} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            treasury_transaction_id bigint(20) unsigned NOT NULL,
            match_type varchar(30) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            amount_irr bigint(20) unsigned NOT NULL,
            matched_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY transaction_entity (treasury_transaction_id, match_type, entity_id),
            KEY entity_lookup (match_type, entity_id),
            KEY transaction_id (treasury_transaction_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$settlements} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            provider_id bigint(20) unsigned NOT NULL,
            treasury_account_id bigint(20) unsigned NOT NULL,
            external_settlement_id varchar(191) NOT NULL,
            gross_amount_irr bigint(20) unsigned NOT NULL,
            fee_amount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            net_amount_irr bigint(20) unsigned NOT NULL,
            settled_at datetime NOT NULL,
            raw_hash char(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY provider_settlement (provider_id, external_settlement_id),
            KEY account_settled (treasury_account_id, settled_at)
        ) {$charset};");

        $required = [$accounts, $providers, $links, $transactions, $matches, $settlements];
        foreach ($required as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required treasury table: ' . $table);
            }
        }
    }
}
