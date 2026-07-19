<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectTreasuryLedger implements Migration
{
    public function id(): string
    {
        return '2026071912_protect_treasury_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $links = $wpdb->prefix . 'rishe_payment_links';
        $transactions = $wpdb->prefix . 'rishe_treasury_transactions';
        $matches = $wpdb->prefix . 'rishe_reconciliation_matches';
        $settlements = $wpdb->prefix . 'rishe_treasury_settlements';
        $triggers = [
            $wpdb->prefix . 'rishe_treasury_link_validate_insert',
            $wpdb->prefix . 'rishe_treasury_link_validate_update',
            $wpdb->prefix . 'rishe_treasury_link_no_delete',
            $wpdb->prefix . 'rishe_treasury_transaction_validate_insert',
            $wpdb->prefix . 'rishe_treasury_transaction_no_update',
            $wpdb->prefix . 'rishe_treasury_transaction_no_delete',
            $wpdb->prefix . 'rishe_treasury_match_validate_insert',
            $wpdb->prefix . 'rishe_treasury_match_no_update',
            $wpdb->prefix . 'rishe_treasury_match_no_delete',
            $wpdb->prefix . 'rishe_treasury_settlement_validate_insert',
            $wpdb->prefix . 'rishe_treasury_settlement_no_update',
            $wpdb->prefix . 'rishe_treasury_settlement_no_delete',
        ];
        foreach ($triggers as $trigger) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $queries = [
            "CREATE TRIGGER {$triggers[0]} BEFORE INSERT ON {$links}
             FOR EACH ROW BEGIN
                IF NEW.amount_irr <= 0 OR NEW.status <> 'creating' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payment link';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[1]} BEFORE UPDATE ON {$links}
             FOR EACH ROW BEGIN
                IF NEW.provider_id <> OLD.provider_id
                   OR NEW.treasury_account_id <> OLD.treasury_account_id
                   OR NOT (NEW.sales_order_id <=> OLD.sales_order_id)
                   OR NOT (NEW.customer_id <=> OLD.customer_id)
                   OR NEW.amount_irr <> OLD.amount_irr
                   OR NEW.idempotency_key <> OLD.idempotency_key
                   OR NEW.payload_hash <> OLD.payload_hash
                   OR NOT (NEW.reference_type <=> OLD.reference_type)
                   OR NOT (NEW.reference_id <=> OLD.reference_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment link commercial fields are immutable';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'creating' AND NEW.status IN ('active', 'failed', 'cancelled'))
                    OR (OLD.status = 'active' AND NEW.status IN ('paid', 'failed', 'expired', 'cancelled'))
                    OR (OLD.status = 'failed' AND NEW.status = 'active')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payment link status transition';
                END IF;
                IF NEW.status = 'paid' AND NEW.paid_transaction_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid payment link requires treasury transaction';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[2]} BEFORE DELETE ON {$links}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment links cannot be deleted'",
            "CREATE TRIGGER {$triggers[3]} BEFORE INSERT ON {$transactions}
             FOR EACH ROW BEGIN
                IF NEW.amount_irr <= 0 OR NEW.direction NOT IN ('credit', 'debit') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid treasury transaction';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[4]} BEFORE UPDATE ON {$transactions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury transactions are immutable'",
            "CREATE TRIGGER {$triggers[5]} BEFORE DELETE ON {$transactions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury transactions are immutable'",
            "CREATE TRIGGER {$triggers[6]} BEFORE INSERT ON {$matches}
             FOR EACH ROW BEGIN
                IF NEW.amount_irr <= 0
                   OR NEW.match_type NOT IN ('sales_order', 'settlement', 'purchase', 'expense', 'manual') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid treasury reconciliation match';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[7]} BEFORE UPDATE ON {$matches}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury matches are immutable'",
            "CREATE TRIGGER {$triggers[8]} BEFORE DELETE ON {$matches}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury matches are immutable'",
            "CREATE TRIGGER {$triggers[9]} BEFORE INSERT ON {$settlements}
             FOR EACH ROW BEGIN
                IF NEW.gross_amount_irr <= 0 OR NEW.net_amount_irr <= 0
                   OR NEW.gross_amount_irr - NEW.fee_amount_irr <> NEW.net_amount_irr THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid treasury settlement';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[10]} BEFORE UPDATE ON {$settlements}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury settlements are immutable'",
            "CREATE TRIGGER {$triggers[11]} BEFORE DELETE ON {$settlements}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury settlements are immutable'",
        ];

        foreach ($queries as $query) {
            if ($wpdb->query($query) === false) {
                throw new RuntimeException('Unable to install treasury database protection: ' . $wpdb->last_error);
            }
        }
    }
}
