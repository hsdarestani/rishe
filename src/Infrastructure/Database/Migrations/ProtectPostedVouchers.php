<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectPostedVouchers implements Migration
{
    public function id(): string
    {
        return '2026071903_protect_posted_vouchers';
    }

    public function up(): void
    {
        global $wpdb;

        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        $safePrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $wpdb->prefix . 'rishe');
        $updateTrigger = $safePrefix . '_journal_vouchers_update_guard';
        $deleteTrigger = $safePrefix . '_journal_vouchers_delete_guard';

        $wpdb->query("DROP TRIGGER IF EXISTS `{$updateTrigger}`");
        $wpdb->query("DROP TRIGGER IF EXISTS `{$deleteTrigger}`");

        $updateSql = "CREATE TRIGGER `{$updateTrigger}` BEFORE UPDATE ON `{$vouchers}`
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'reversed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reversed vouchers are immutable';
                ELSEIF OLD.status = 'posted' THEN
                    IF NEW.status <> 'reversed'
                        OR NOT (NEW.public_id <=> OLD.public_id)
                        OR NOT (NEW.fiscal_year <=> OLD.fiscal_year)
                        OR NOT (NEW.voucher_number <=> OLD.voucher_number)
                        OR NOT (NEW.voucher_date <=> OLD.voucher_date)
                        OR NOT (NEW.description <=> OLD.description)
                        OR NOT (NEW.total_debit <=> OLD.total_debit)
                        OR NOT (NEW.total_credit <=> OLD.total_credit)
                        OR NOT (NEW.reversal_of_id <=> OLD.reversal_of_id)
                        OR NOT (NEW.correlation_id <=> OLD.correlation_id)
                        OR NOT (NEW.created_by <=> OLD.created_by)
                        OR NOT (NEW.posted_by <=> OLD.posted_by)
                        OR NOT (NEW.posted_at <=> OLD.posted_at)
                        OR NOT (NEW.created_at <=> OLD.created_at)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted vouchers are immutable';
                    END IF;
                END IF;
            END";

        $deleteSql = "CREATE TRIGGER `{$deleteTrigger}` BEFORE DELETE ON `{$vouchers}`
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('posted', 'reversed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted vouchers cannot be deleted';
                END IF;
            END";

        if ($wpdb->query($updateSql) === false || $wpdb->query($deleteSql) === false) {
            throw new RuntimeException('Unable to create posted-voucher immutability guards.');
        }
    }
}
