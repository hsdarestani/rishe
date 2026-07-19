<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class HardenB2BAccountGuard implements Migration
{
    public function id(): string
    {
        return '2026071916_harden_b2b_account_guard';
    }

    public function up(): void
    {
        global $wpdb;

        $accounts = $wpdb->prefix . 'rishe_b2b_accounts';
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);
        $name = $prefix . 'rishe_b2b_account_guard_update';
        $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
        $body = "
            BEFORE UPDATE ON {$accounts} FOR EACH ROW
            BEGIN
                IF NEW.current_receivable_irr > NEW.credit_limit_irr THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B receivable exceeds credit limit';
                END IF;
                IF NEW.commission_rate_bps > 10000 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B commission rate is invalid';
                END IF;
                IF OLD.current_receivable_irr > 0 AND (
                    NEW.customer_id <> OLD.customer_id OR NEW.code <> OLD.code OR
                    NEW.account_type <> OLD.account_type OR
                    NEW.consignment_warehouse_id <> OLD.consignment_warehouse_id
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B identity cannot change with an open balance';
                END IF;
                IF NEW.status <> 'active' AND NEW.current_receivable_irr > 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B account with open receivable must remain active';
                END IF;
            END";
        if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
            throw new RuntimeException('Unable to harden B2B account guard: ' . $wpdb->last_error);
        }
    }
}
