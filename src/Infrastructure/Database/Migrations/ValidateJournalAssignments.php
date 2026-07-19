<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ValidateJournalAssignments implements Migration
{
    public function id(): string
    {
        return '2026071904_validate_journal_assignments';
    }

    public function up(): void
    {
        global $wpdb;

        $entries = $wpdb->prefix . 'rishe_journal_entries';
        $subsidiary = $wpdb->prefix . 'rishe_subsidiary_ledgers';
        $details = $wpdb->prefix . 'rishe_floating_details';
        $safePrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $wpdb->prefix . 'rishe');
        $trigger = $safePrefix . '_journal_entries_insert_validation';

        $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`");

        $sql = "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$entries}`
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM `{$subsidiary}`
                    WHERE id = NEW.subsidiary_ledger_id AND is_active = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal entry subsidiary ledger is invalid';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM `{$subsidiary}`
                    WHERE id = NEW.subsidiary_ledger_id
                        AND requires_floating_detail = 1
                ) AND NEW.floating_detail_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal entry requires a floating detail';
                END IF;

                IF NEW.floating_detail_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM `{$details}`
                    WHERE id = NEW.floating_detail_id AND is_active = 1
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal entry floating detail is invalid';
                END IF;
            END";

        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to create journal assignment validation guard.');
        }
    }
}
