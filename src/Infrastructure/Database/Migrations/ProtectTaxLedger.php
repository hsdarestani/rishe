<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectTaxLedger implements Migration
{
    public function id(): string
    {
        return '2026071920_protect_tax_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $invoices = $wpdb->prefix . 'rishe_tax_invoices';
        $lines = $wpdb->prefix . 'rishe_tax_invoice_lines';
        $payments = $wpdb->prefix . 'rishe_tax_invoice_payments';
        $submissions = $wpdb->prefix . 'rishe_tax_submissions';
        $events = $wpdb->prefix . 'rishe_tax_status_events';
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);
        $triggers = [
            "{$prefix}rishe_tax_invoice_guard_update" => "
                BEFORE UPDATE ON {$invoices} FOR EACH ROW
                BEGIN
                    IF OLD.status <> 'draft' AND (
                        NEW.profile_id <> OLD.profile_id OR NOT (NEW.sales_order_id <=> OLD.sales_order_id) OR
                        NOT (NEW.source_invoice_id <=> OLD.source_invoice_id) OR NEW.subject <> OLD.subject OR
                        NEW.subject_code <> OLD.subject_code OR NEW.invoice_type <> OLD.invoice_type OR
                        NEW.invoice_pattern <> OLD.invoice_pattern OR NEW.settlement_method <> OLD.settlement_method OR
                        NEW.buyer_type <> OLD.buyer_type OR NOT (NEW.buyer_national_id <=> OLD.buyer_national_id) OR
                        NOT (NEW.buyer_economic_code <=> OLD.buyer_economic_code) OR
                        NEW.seller_national_id <> OLD.seller_national_id OR
                        NEW.seller_economic_code <> OLD.seller_economic_code OR NEW.gross_irr <> OLD.gross_irr OR
                        NEW.discount_irr <> OLD.discount_irr OR NEW.net_irr <> OLD.net_irr OR
                        NEW.vat_irr <> OLD.vat_irr OR NEW.total_irr <> OLD.total_irr OR
                        NEW.cash_irr <> OLD.cash_irr OR NEW.credit_irr <> OLD.credit_irr OR
                        NEW.source_hash <> OLD.source_hash
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Frozen tax invoice snapshot is immutable';
                    END IF;
                    IF OLD.tax_number IS NOT NULL AND NEW.tax_number <> OLD.tax_number THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoice number is immutable';
                    END IF;
                    IF OLD.payload_sha256 IS NOT NULL AND NEW.payload_sha256 <> OLD.payload_sha256 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax payload is immutable';
                    END IF;
                    IF NEW.submission_attempts < OLD.submission_attempts THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax submission attempts are monotonic';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'draft' AND NEW.status = 'frozen') OR
                        (OLD.status IN ('frozen','rejected','submitted') AND NEW.status IN ('submitted','accepted','rejected')) OR
                        (OLD.status IN ('accepted','submitted') AND NEW.status IN ('corrected','cancelled','returned'))
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid tax invoice lifecycle transition';
                    END IF;
                END",
            "{$prefix}rishe_tax_invoice_guard_delete" => "
                BEFORE DELETE ON {$invoices} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoices cannot be deleted';
                END",
            "{$prefix}rishe_tax_line_guard_update" => "
                BEFORE UPDATE ON {$lines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoice lines are immutable';
                END",
            "{$prefix}rishe_tax_line_guard_delete" => "
                BEFORE DELETE ON {$lines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoice lines cannot be deleted';
                END",
            "{$prefix}rishe_tax_payment_guard_update" => "
                BEFORE UPDATE ON {$payments} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoice payments are immutable';
                END",
            "{$prefix}rishe_tax_payment_guard_delete" => "
                BEFORE DELETE ON {$payments} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax invoice payments cannot be deleted';
                END",
            "{$prefix}rishe_tax_submission_guard_update" => "
                BEFORE UPDATE ON {$submissions} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax submissions are append-only';
                END",
            "{$prefix}rishe_tax_submission_guard_delete" => "
                BEFORE DELETE ON {$submissions} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax submissions are append-only';
                END",
            "{$prefix}rishe_tax_event_guard_update" => "
                BEFORE UPDATE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax status events are append-only';
                END",
            "{$prefix}rishe_tax_event_guard_delete" => "
                BEFORE DELETE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tax status events are append-only';
                END",
        ];

        foreach ($triggers as $name => $body) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
                throw new RuntimeException('Unable to create tax trigger ' . $name . ': ' . $wpdb->last_error);
            }
        }
    }
}
