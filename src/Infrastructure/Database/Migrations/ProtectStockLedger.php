<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectStockLedger implements Migration
{
    public function id(): string
    {
        return '2026071906_protect_stock_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $allocations = $wpdb->prefix . 'rishe_stock_reservation_allocations';
        $reservations = $wpdb->prefix . 'rishe_stock_reservations';
        $movements = $wpdb->prefix . 'rishe_stock_movements';
        $triggers = [
            $wpdb->prefix . 'rishe_batch_balance_insert',
            $wpdb->prefix . 'rishe_batch_balance_update',
            $wpdb->prefix . 'rishe_movement_validate_insert',
            $wpdb->prefix . 'rishe_allocation_validate_insert',
            $wpdb->prefix . 'rishe_reservation_validate_insert',
            $wpdb->prefix . 'rishe_movement_no_update',
            $wpdb->prefix . 'rishe_movement_no_delete',
            $wpdb->prefix . 'rishe_allocation_no_update',
            $wpdb->prefix . 'rishe_allocation_no_delete',
        ];
        foreach ($triggers as $trigger) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $queries = [
            "CREATE TRIGGER {$triggers[0]} BEFORE INSERT ON {$batches}
             FOR EACH ROW BEGIN
                IF NEW.quantity_on_hand < 0 OR NEW.quantity_reserved < 0
                   OR NEW.quantity_reserved > NEW.quantity_on_hand THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid inventory batch balance';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[1]} BEFORE UPDATE ON {$batches}
             FOR EACH ROW BEGIN
                IF NEW.quantity_on_hand < 0 OR NEW.quantity_reserved < 0
                   OR NEW.quantity_reserved > NEW.quantity_on_hand THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid inventory batch balance';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[2]} BEFORE INSERT ON {$movements}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled = 0
                   OR (NEW.type IN ('receipt', 'transfer_in', 'adjustment_in') AND NEW.quantity_scaled < 0)
                   OR (NEW.type IN ('issue', 'transfer_out', 'adjustment_out') AND NEW.quantity_scaled > 0) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid stock movement direction';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[3]} BEFORE INSERT ON {$allocations}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled <= 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservation allocation must be positive';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[4]} BEFORE INSERT ON {$reservations}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled <= 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservation quantity must be positive';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[5]} BEFORE UPDATE ON {$movements}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'",
            "CREATE TRIGGER {$triggers[6]} BEFORE DELETE ON {$movements}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'",
            "CREATE TRIGGER {$triggers[7]} BEFORE UPDATE ON {$allocations}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservation allocations are immutable'",
            "CREATE TRIGGER {$triggers[8]} BEFORE DELETE ON {$allocations}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reservation allocations are immutable'",
        ];

        foreach ($queries as $query) {
            if ($wpdb->query($query) === false) {
                throw new RuntimeException('Unable to install inventory database protection: ' . $wpdb->last_error);
            }
        }
    }
}
