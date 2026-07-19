<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectB2BLedger implements Migration
{
    public function id(): string
    {
        return '2026071916_protect_b2b_consignment_ledger';
    }

    public function up(): void
    {
        global $wpdb;

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
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);

        $triggers = [
            "{$prefix}rishe_b2b_account_guard_update" => "
                BEFORE UPDATE ON {$accounts} FOR EACH ROW
                BEGIN
                    IF NEW.current_receivable_irr > NEW.credit_limit_irr THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B receivable exceeds credit limit';
                    END IF;
                    IF OLD.current_receivable_irr <> NEW.current_receivable_irr AND (
                        NEW.customer_id <> OLD.customer_id OR NEW.code <> OLD.code
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B identity cannot change with an open balance';
                    END IF;
                END",
            "{$prefix}rishe_b2b_account_guard_delete" => "
                BEFORE DELETE ON {$accounts} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B accounts cannot be deleted';
                END",
            "{$prefix}rishe_dispatch_guard_update" => "
                BEFORE UPDATE ON {$dispatches} FOR EACH ROW
                BEGIN
                    IF OLD.status <> 'posting' AND (
                        NEW.account_id <> OLD.account_id OR NEW.source_warehouse_id <> OLD.source_warehouse_id OR
                        NEW.destination_warehouse_id <> OLD.destination_warehouse_id OR NEW.payload_hash <> OLD.payload_hash
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted consignment dispatch is immutable';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'posting' AND NEW.status = 'active') OR
                        (OLD.status = 'active' AND NEW.status IN ('partially_settled', 'closed')) OR
                        (OLD.status = 'partially_settled' AND NEW.status IN ('partially_settled', 'closed'))
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid consignment dispatch lifecycle';
                    END IF;
                END",
            "{$prefix}rishe_dispatch_guard_delete" => "
                BEFORE DELETE ON {$dispatches} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment dispatches cannot be deleted';
                END",
            "{$prefix}rishe_dispatch_line_guard_update" => "
                BEFORE UPDATE ON {$dispatchLines} FOR EACH ROW
                BEGIN
                    IF OLD.transfer_group_id IS NOT NULL AND (
                        NEW.dispatch_id <> OLD.dispatch_id OR NEW.product_id <> OLD.product_id OR
                        NEW.quantity_scaled <> OLD.quantity_scaled OR NEW.transfer_group_id <> OLD.transfer_group_id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transferred dispatch line is immutable';
                    END IF;
                    IF NEW.sold_quantity_scaled < OLD.sold_quantity_scaled OR
                       NEW.returned_quantity_scaled < OLD.returned_quantity_scaled OR
                       NEW.sold_quantity_scaled + NEW.returned_quantity_scaled > NEW.quantity_scaled THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid consignment dispatch line balance';
                    END IF;
                END",
            "{$prefix}rishe_dispatch_line_guard_delete" => "
                BEFORE DELETE ON {$dispatchLines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment dispatch lines cannot be deleted';
                END",
            "{$prefix}rishe_return_guard_update" => "
                BEFORE UPDATE ON {$returns} FOR EACH ROW
                BEGIN
                    IF OLD.status = 'posted' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted consignment return is immutable';
                    END IF;
                    IF NOT (NEW.status = OLD.status OR (OLD.status = 'posting' AND NEW.status = 'posted')) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid consignment return lifecycle';
                    END IF;
                END",
            "{$prefix}rishe_return_guard_delete" => "
                BEFORE DELETE ON {$returns} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment returns cannot be deleted';
                END",
            "{$prefix}rishe_return_line_guard_update" => "
                BEFORE UPDATE ON {$returnLines} FOR EACH ROW
                BEGIN
                    IF OLD.transfer_group_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transferred return line is immutable';
                    END IF;
                END",
            "{$prefix}rishe_return_line_guard_delete" => "
                BEFORE DELETE ON {$returnLines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment return lines cannot be deleted';
                END",
            "{$prefix}rishe_sales_report_guard_update" => "
                BEFORE UPDATE ON {$reports} FOR EACH ROW
                BEGIN
                    IF OLD.status = 'posted' AND (
                        NEW.account_id <> OLD.account_id OR NEW.warehouse_id <> OLD.warehouse_id OR
                        NEW.gross_irr <> OLD.gross_irr OR NEW.commission_irr <> OLD.commission_irr OR
                        NEW.receivable_irr <> OLD.receivable_irr OR NEW.payload_hash <> OLD.payload_hash
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted agent sales report is immutable';
                    END IF;
                    IF NOT (NEW.status = OLD.status OR (OLD.status = 'posting' AND NEW.status = 'posted')) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid agent sales report lifecycle';
                    END IF;
                END",
            "{$prefix}rishe_sales_report_guard_delete" => "
                BEFORE DELETE ON {$reports} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent sales reports cannot be deleted';
                END",
            "{$prefix}rishe_sales_report_line_guard_update" => "
                BEFORE UPDATE ON {$reportLines} FOR EACH ROW
                BEGIN
                    IF OLD.reservation_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consumed agent sales line is immutable';
                    END IF;
                END",
            "{$prefix}rishe_sales_report_line_guard_delete" => "
                BEFORE DELETE ON {$reportLines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent sales report lines cannot be deleted';
                END",
            "{$prefix}rishe_sale_allocation_guard_update" => "
                BEFORE UPDATE ON {$allocations} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment sale allocations are immutable';
                END",
            "{$prefix}rishe_sale_allocation_guard_delete" => "
                BEFORE DELETE ON {$allocations} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Consignment sale allocations are immutable';
                END",
            "{$prefix}rishe_b2b_ledger_guard_update" => "
                BEFORE UPDATE ON {$ledger} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B ledger is append-only';
                END",
            "{$prefix}rishe_b2b_ledger_guard_delete" => "
                BEFORE DELETE ON {$ledger} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B ledger is append-only';
                END",
            "{$prefix}rishe_b2b_settlement_guard_update" => "
                BEFORE UPDATE ON {$settlements} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B settlements are immutable';
                END",
            "{$prefix}rishe_b2b_settlement_guard_delete" => "
                BEFORE DELETE ON {$settlements} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2B settlements are immutable';
                END",
        ];

        foreach ($triggers as $name => $body) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
                throw new RuntimeException('Unable to create B2B trigger ' . $name . ': ' . $wpdb->last_error);
            }
        }
    }
}
