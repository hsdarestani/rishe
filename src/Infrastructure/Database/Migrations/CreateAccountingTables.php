<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateAccountingTables implements Migration
{
    public function id(): string
    {
        return '2026071902_create_accounting_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $groups = $wpdb->prefix . 'rishe_account_groups';
        $general = $wpdb->prefix . 'rishe_general_ledgers';
        $subsidiary = $wpdb->prefix . 'rishe_subsidiary_ledgers';
        $details = $wpdb->prefix . 'rishe_floating_details';
        $sequences = $wpdb->prefix . 'rishe_voucher_sequences';
        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        $entries = $wpdb->prefix . 'rishe_journal_entries';

        dbDelta("CREATE TABLE {$groups} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            code varchar(20) NOT NULL,
            name varchar(191) NOT NULL,
            normal_balance varchar(10) NOT NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY is_active (is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$general} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            account_group_id bigint(20) unsigned NOT NULL,
            code varchar(20) NOT NULL,
            name varchar(191) NOT NULL,
            normal_balance varchar(10) NOT NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY account_group_id (account_group_id),
            KEY is_active (is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$subsidiary} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            general_ledger_id bigint(20) unsigned NOT NULL,
            code varchar(30) NOT NULL,
            name varchar(191) NOT NULL,
            normal_balance varchar(10) NOT NULL,
            requires_floating_detail tinyint(1) unsigned NOT NULL DEFAULT 0,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY general_ledger_id (general_ledger_id),
            KEY is_active (is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$details} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            detail_type varchar(40) NOT NULL,
            external_reference varchar(191) NULL,
            code varchar(40) NOT NULL,
            name varchar(191) NOT NULL,
            mobile varchar(20) NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY code (code),
            KEY type_reference (detail_type, external_reference),
            KEY mobile (mobile),
            KEY is_active (is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sequences} (
            fiscal_year smallint(5) unsigned NOT NULL,
            last_number bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (fiscal_year)
        ) {$charset};");

        dbDelta("CREATE TABLE {$vouchers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            fiscal_year smallint(5) unsigned NOT NULL,
            voucher_number bigint(20) unsigned NULL,
            voucher_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            description text NULL,
            total_debit bigint(20) unsigned NOT NULL DEFAULT 0,
            total_credit bigint(20) unsigned NOT NULL DEFAULT 0,
            reversal_of_id bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NULL,
            posted_by bigint(20) unsigned NULL,
            posted_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY fiscal_number (fiscal_year, voucher_number),
            KEY status_date (status, voucher_date),
            KEY reversal_of_id (reversal_of_id),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$entries} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            voucher_id bigint(20) unsigned NOT NULL,
            line_number smallint(5) unsigned NOT NULL,
            subsidiary_ledger_id bigint(20) unsigned NOT NULL,
            floating_detail_id bigint(20) unsigned NULL,
            debit bigint(20) unsigned NOT NULL DEFAULT 0,
            credit bigint(20) unsigned NOT NULL DEFAULT 0,
            description varchar(500) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY voucher_line (voucher_id, line_number),
            KEY subsidiary_ledger_id (subsidiary_ledger_id),
            KEY floating_detail_id (floating_detail_id),
            KEY voucher_id (voucher_id),
            CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
        ) {$charset};");

        $this->assertTablesExist([$groups, $general, $subsidiary, $details, $sequences, $vouchers, $entries]);
        $this->createLedgerGuards($vouchers, $entries);
    }

    /** @param list<string> $tables */
    private function assertTablesExist(array $tables): void
    {
        global $wpdb;

        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException("Unable to create required accounting table: {$table}");
            }
        }
    }

    private function createLedgerGuards(string $vouchers, string $entries): void
    {
        global $wpdb;

        $safePrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $wpdb->prefix . 'rishe');
        $updateTrigger = $safePrefix . '_journal_entries_update_guard';
        $deleteTrigger = $safePrefix . '_journal_entries_delete_guard';

        $wpdb->query("DROP TRIGGER IF EXISTS `{$updateTrigger}`");
        $wpdb->query("DROP TRIGGER IF EXISTS `{$deleteTrigger}`");

        $updateSql = "CREATE TRIGGER `{$updateTrigger}` BEFORE UPDATE ON `{$entries}`
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM `{$vouchers}`
                    WHERE id = OLD.voucher_id AND status IN ('posted', 'reversed')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable';
                END IF;
            END";

        $deleteSql = "CREATE TRIGGER `{$deleteTrigger}` BEFORE DELETE ON `{$entries}`
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM `{$vouchers}`
                    WHERE id = OLD.voucher_id AND status IN ('posted', 'reversed')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries cannot be deleted';
                END IF;
            END";

        if ($wpdb->query($updateSql) === false || $wpdb->query($deleteSql) === false) {
            throw new RuntimeException('Unable to create accounting ledger immutability guards.');
        }
    }
}
