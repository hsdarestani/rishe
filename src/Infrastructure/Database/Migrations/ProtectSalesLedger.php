<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectSalesLedger implements Migration
{
    public function id(): string
    {
        return '2026071910_protect_sales_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $customers = $wpdb->prefix . 'rishe_customers';
        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $payments = $wpdb->prefix . 'rishe_sales_payments';
        $redemptions = $wpdb->prefix . 'rishe_promotion_redemptions';
        $loyalty = $wpdb->prefix . 'rishe_loyalty_ledger';
        $history = $wpdb->prefix . 'rishe_order_status_history';
        $triggers = [
            $wpdb->prefix . 'rishe_customer_loyalty_insert',
            $wpdb->prefix . 'rishe_customer_loyalty_update',
            $wpdb->prefix . 'rishe_sales_order_validate_insert',
            $wpdb->prefix . 'rishe_sales_order_validate_update',
            $wpdb->prefix . 'rishe_sales_order_no_delete',
            $wpdb->prefix . 'rishe_sales_line_validate_insert',
            $wpdb->prefix . 'rishe_sales_line_protect_update',
            $wpdb->prefix . 'rishe_sales_line_no_delete',
            $wpdb->prefix . 'rishe_sales_payment_validate_insert',
            $wpdb->prefix . 'rishe_sales_payment_no_update',
            $wpdb->prefix . 'rishe_sales_payment_no_delete',
            $wpdb->prefix . 'rishe_promotion_redemption_no_update',
            $wpdb->prefix . 'rishe_promotion_redemption_no_delete',
            $wpdb->prefix . 'rishe_loyalty_validate_insert',
            $wpdb->prefix . 'rishe_loyalty_no_update',
            $wpdb->prefix . 'rishe_loyalty_no_delete',
            $wpdb->prefix . 'rishe_order_history_no_update',
            $wpdb->prefix . 'rishe_order_history_no_delete',
        ];
        foreach ($triggers as $trigger) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $queries = [
            "CREATE TRIGGER {$triggers[0]} BEFORE INSERT ON {$customers}
             FOR EACH ROW BEGIN
                IF NEW.loyalty_balance < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer loyalty balance cannot be negative';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[1]} BEFORE UPDATE ON {$customers}
             FOR EACH ROW BEGIN
                IF NEW.loyalty_balance < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer loyalty balance cannot be negative';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[2]} BEFORE INSERT ON {$orders}
             FOR EACH ROW BEGIN
                IF NEW.status <> 'pending_payment'
                   OR NEW.currency <> 'IRR'
                   OR NEW.subtotal_irr <> NEW.gross_irr - NEW.line_discount_irr
                   OR NEW.total_irr <> NEW.subtotal_irr - NEW.promotion_discount_irr
                       - NEW.loyalty_discount_irr + NEW.shipping_irr + NEW.tax_irr
                   OR NEW.promotion_discount_irr + NEW.loyalty_discount_irr > NEW.subtotal_irr THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid sales order totals or initial status';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[3]} BEFORE UPDATE ON {$orders}
             FOR EACH ROW BEGIN
                IF NOT (NEW.channel <=> OLD.channel)
                   OR NOT (NEW.external_order_id <=> OLD.external_order_id)
                   OR NOT (NEW.idempotency_key <=> OLD.idempotency_key)
                   OR NOT (NEW.source_hash <=> OLD.source_hash)
                   OR NOT (NEW.customer_id <=> OLD.customer_id)
                   OR NOT (NEW.warehouse_id <=> OLD.warehouse_id)
                   OR NOT (NEW.currency <=> OLD.currency)
                   OR NOT (NEW.gross_irr <=> OLD.gross_irr)
                   OR NOT (NEW.line_discount_irr <=> OLD.line_discount_irr)
                   OR NOT (NEW.subtotal_irr <=> OLD.subtotal_irr)
                   OR NOT (NEW.promotion_discount_irr <=> OLD.promotion_discount_irr)
                   OR NOT (NEW.loyalty_discount_irr <=> OLD.loyalty_discount_irr)
                   OR NOT (NEW.shipping_irr <=> OLD.shipping_irr)
                   OR NOT (NEW.tax_irr <=> OLD.tax_irr)
                   OR NOT (NEW.total_irr <=> OLD.total_irr)
                   OR NOT (NEW.loyalty_points_redeemed <=> OLD.loyalty_points_redeemed)
                   OR NOT (NEW.promotion_id <=> OLD.promotion_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales order commercial fields are immutable';
                END IF;
                IF NEW.status <> OLD.status
                   AND NOT (
                       (OLD.status = 'pending_payment' AND NEW.status IN ('paid', 'cancelled'))
                       OR (OLD.status = 'paid' AND NEW.status IN ('fulfilling', 'completed', 'refunded'))
                       OR (OLD.status = 'fulfilling' AND NEW.status IN ('completed', 'refunded'))
                       OR (OLD.status = 'completed' AND NEW.status = 'refunded')
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid sales order status transition';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[4]} BEFORE DELETE ON {$orders}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales orders cannot be deleted'",
            "CREATE TRIGGER {$triggers[5]} BEFORE INSERT ON {$lines}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled <= 0
                   OR NEW.line_discount_irr > NEW.gross_irr
                   OR NEW.net_irr <> NEW.gross_irr - NEW.line_discount_irr THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid sales order line';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[6]} BEFORE UPDATE ON {$lines}
             FOR EACH ROW BEGIN
                IF NOT (NEW.order_id <=> OLD.order_id)
                   OR NOT (NEW.product_id <=> OLD.product_id)
                   OR NOT (NEW.sku_snapshot <=> OLD.sku_snapshot)
                   OR NOT (NEW.name_snapshot <=> OLD.name_snapshot)
                   OR NOT (NEW.quantity_scaled <=> OLD.quantity_scaled)
                   OR NOT (NEW.unit_price_irr <=> OLD.unit_price_irr)
                   OR NOT (NEW.gross_irr <=> OLD.gross_irr)
                   OR NOT (NEW.line_discount_irr <=> OLD.line_discount_irr)
                   OR NOT (NEW.net_irr <=> OLD.net_irr) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales order line commercial fields are immutable';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[7]} BEFORE DELETE ON {$lines}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales order lines cannot be deleted'",
            "CREATE TRIGGER {$triggers[8]} BEFORE INSERT ON {$payments}
             FOR EACH ROW BEGIN
                IF NEW.amount_irr <= 0 OR NEW.status <> 'captured' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid captured sales payment';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[9]} BEFORE UPDATE ON {$payments}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured sales payments are immutable'",
            "CREATE TRIGGER {$triggers[10]} BEFORE DELETE ON {$payments}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured sales payments are immutable'",
            "CREATE TRIGGER {$triggers[11]} BEFORE UPDATE ON {$redemptions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemptions are immutable'",
            "CREATE TRIGGER {$triggers[12]} BEFORE DELETE ON {$redemptions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemptions are immutable'",
            "CREATE TRIGGER {$triggers[13]} BEFORE INSERT ON {$loyalty}
             FOR EACH ROW BEGIN
                IF NEW.points = 0 OR NEW.balance_after < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid loyalty ledger entry';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[14]} BEFORE UPDATE ON {$loyalty}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loyalty ledger is immutable'",
            "CREATE TRIGGER {$triggers[15]} BEFORE DELETE ON {$loyalty}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loyalty ledger is immutable'",
            "CREATE TRIGGER {$triggers[16]} BEFORE UPDATE ON {$history}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order status history is immutable'",
            "CREATE TRIGGER {$triggers[17]} BEFORE DELETE ON {$history}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order status history is immutable'",
        ];

        foreach ($queries as $query) {
            if ($wpdb->query($query) === false) {
                throw new RuntimeException('Unable to install sales database protection: ' . $wpdb->last_error);
            }
        }
    }
}
