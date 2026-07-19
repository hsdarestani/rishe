<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectProcurementLedger implements Migration
{
    public function id(): string
    {
        return '2026071914_protect_procurement_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_purchase_orders';
        $orderLines = $wpdb->prefix . 'rishe_purchase_order_lines';
        $receipts = $wpdb->prefix . 'rishe_purchase_receipts';
        $receiptLines = $wpdb->prefix . 'rishe_purchase_receipt_lines';
        $costs = $wpdb->prefix . 'rishe_purchase_landed_costs';
        $ledger = $wpdb->prefix . 'rishe_supplier_ledger';
        $payments = $wpdb->prefix . 'rishe_purchase_payments';
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);

        $triggers = [
            "{$prefix}rishe_po_guard_update" => "
                BEFORE UPDATE ON {$orders} FOR EACH ROW
                BEGIN
                    IF OLD.status <> 'draft' AND (
                        NEW.supplier_id <> OLD.supplier_id OR NEW.warehouse_id <> OLD.warehouse_id OR
                        NEW.merchandise_gross_irr <> OLD.merchandise_gross_irr OR
                        NEW.discount_irr <> OLD.discount_irr OR NEW.merchandise_net_irr <> OLD.merchandise_net_irr OR
                        NEW.tax_irr <> OLD.tax_irr OR NEW.estimated_landed_cost_irr <> OLD.estimated_landed_cost_irr OR
                        NEW.estimated_total_irr <> OLD.estimated_total_irr OR NEW.payload_hash <> OLD.payload_hash
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved purchase commercial fields are immutable';
                    END IF;
                    IF NEW.received_liability_irr < OLD.received_liability_irr OR NEW.paid_irr < OLD.paid_irr OR
                       NEW.paid_irr > NEW.received_liability_irr THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid purchase liability balances';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'draft' AND NEW.status IN ('approved', 'cancelled')) OR
                        (OLD.status = 'approved' AND NEW.status IN ('partially_received', 'received', 'cancelled')) OR
                        (OLD.status = 'partially_received' AND NEW.status IN ('partially_received', 'received'))
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid purchase-order lifecycle transition';
                    END IF;
                END",
            "{$prefix}rishe_po_guard_delete" => "
                BEFORE DELETE ON {$orders} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase orders cannot be deleted';
                END",
            "{$prefix}rishe_po_line_guard_update" => "
                BEFORE UPDATE ON {$orderLines} FOR EACH ROW
                BEGIN
                    DECLARE parent_status varchar(30);
                    SELECT status INTO parent_status FROM {$orders} WHERE id = OLD.purchase_order_id;
                    IF parent_status <> 'draft' AND (
                        NEW.product_id <> OLD.product_id OR NEW.quantity_scaled <> OLD.quantity_scaled OR
                        NEW.unit_price_irr <> OLD.unit_price_irr OR NEW.discount_irr <> OLD.discount_irr OR
                        NEW.tax_irr <> OLD.tax_irr OR NEW.line_total_irr <> OLD.line_total_irr
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved purchase-order lines are immutable';
                    END IF;
                    IF NEW.received_quantity_scaled < OLD.received_quantity_scaled OR
                       NEW.received_quantity_scaled > NEW.quantity_scaled OR
                       NEW.received_inventory_value_irr < OLD.received_inventory_value_irr OR
                       NEW.received_tax_irr < OLD.received_tax_irr THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid received purchase-line totals';
                    END IF;
                END",
            "{$prefix}rishe_po_line_guard_delete" => "
                BEFORE DELETE ON {$orderLines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase-order lines cannot be deleted';
                END",
            "{$prefix}rishe_receipt_guard_update" => "
                BEFORE UPDATE ON {$receipts} FOR EACH ROW
                BEGIN
                    IF OLD.status = 'posted' AND (
                        NEW.purchase_order_id <> OLD.purchase_order_id OR NEW.supplier_id <> OLD.supplier_id OR
                        NEW.warehouse_id <> OLD.warehouse_id OR NEW.merchandise_value_irr <> OLD.merchandise_value_irr OR
                        NEW.tax_irr <> OLD.tax_irr OR NEW.landed_cost_irr <> OLD.landed_cost_irr OR
                        NEW.liability_irr <> OLD.liability_irr OR NEW.payload_hash <> OLD.payload_hash
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted purchase receipts are immutable';
                    END IF;
                    IF NOT (NEW.status = OLD.status OR (OLD.status = 'posting' AND NEW.status = 'posted')) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid purchase-receipt lifecycle transition';
                    END IF;
                END",
            "{$prefix}rishe_receipt_guard_delete" => "
                BEFORE DELETE ON {$receipts} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase receipts cannot be deleted';
                END",
            "{$prefix}rishe_receipt_line_guard_update" => "
                BEFORE UPDATE ON {$receiptLines} FOR EACH ROW
                BEGIN
                    IF OLD.inventory_batch_id IS NOT NULL OR (
                        NEW.purchase_receipt_id <> OLD.purchase_receipt_id OR
                        NEW.purchase_order_line_id <> OLD.purchase_order_line_id OR
                        NEW.product_id <> OLD.product_id OR NEW.quantity_scaled <> OLD.quantity_scaled OR
                        NEW.merchandise_value_irr <> OLD.merchandise_value_irr OR NEW.tax_irr <> OLD.tax_irr OR
                        NEW.landed_cost_irr <> OLD.landed_cost_irr OR NEW.liability_irr <> OLD.liability_irr OR
                        NEW.unit_cost_irr <> OLD.unit_cost_irr OR NEW.batch_code <> OLD.batch_code
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase-receipt lines are immutable after batch attachment';
                    END IF;
                END",
            "{$prefix}rishe_receipt_line_guard_delete" => "
                BEFORE DELETE ON {$receiptLines} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase-receipt lines cannot be deleted';
                END",
            "{$prefix}rishe_cost_guard_update" => "
                BEFORE UPDATE ON {$costs} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Landed costs are immutable';
                END",
            "{$prefix}rishe_cost_guard_delete" => "
                BEFORE DELETE ON {$costs} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Landed costs cannot be deleted';
                END",
            "{$prefix}rishe_supplier_ledger_guard_update" => "
                BEFORE UPDATE ON {$ledger} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier ledger is append-only';
                END",
            "{$prefix}rishe_supplier_ledger_guard_delete" => "
                BEFORE DELETE ON {$ledger} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier ledger is append-only';
                END",
            "{$prefix}rishe_purchase_payment_guard_update" => "
                BEFORE UPDATE ON {$payments} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase payments are immutable';
                END",
            "{$prefix}rishe_purchase_payment_guard_delete" => "
                BEFORE DELETE ON {$payments} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase payments are immutable';
                END",
        ];

        foreach ($triggers as $name => $body) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
                throw new RuntimeException('Unable to create procurement trigger ' . $name . ': ' . $wpdb->last_error);
            }
        }
    }
}
