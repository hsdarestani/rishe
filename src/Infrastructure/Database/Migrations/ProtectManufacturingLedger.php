<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectManufacturingLedger implements Migration
{
    public function id(): string
    {
        return '2026071908_protect_manufacturing_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $boms = $wpdb->prefix . 'rishe_boms';
        $components = $wpdb->prefix . 'rishe_bom_components';
        $orders = $wpdb->prefix . 'rishe_production_orders';
        $consumptions = $wpdb->prefix . 'rishe_production_consumptions';
        $outputs = $wpdb->prefix . 'rishe_production_outputs';
        $movements = $wpdb->prefix . 'rishe_stock_movements';
        $triggers = [
            $wpdb->prefix . 'rishe_bom_validate_insert',
            $wpdb->prefix . 'rishe_bom_validate_update',
            $wpdb->prefix . 'rishe_bom_component_validate_insert',
            $wpdb->prefix . 'rishe_bom_component_guard_update',
            $wpdb->prefix . 'rishe_bom_component_guard_delete',
            $wpdb->prefix . 'rishe_production_order_validate_insert',
            $wpdb->prefix . 'rishe_production_order_guard_update',
            $wpdb->prefix . 'rishe_production_consumption_validate_insert',
            $wpdb->prefix . 'rishe_production_consumption_no_update',
            $wpdb->prefix . 'rishe_production_consumption_no_delete',
            $wpdb->prefix . 'rishe_production_output_validate_insert',
            $wpdb->prefix . 'rishe_production_output_no_update',
            $wpdb->prefix . 'rishe_production_output_no_delete',
            $wpdb->prefix . 'rishe_movement_validate_insert',
        ];
        foreach ($triggers as $trigger) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $queries = [
            "CREATE TRIGGER {$triggers[0]} BEFORE INSERT ON {$boms}
             FOR EACH ROW BEGIN
                IF NEW.output_quantity_scaled <= 0 OR NEW.version <= 0
                   OR NEW.status NOT IN ('draft', 'active', 'retired') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid BOM header';
                END IF;
                IF NEW.effective_from IS NOT NULL AND NEW.effective_to IS NOT NULL
                   AND NEW.effective_from > NEW.effective_to THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid BOM effective dates';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[1]} BEFORE UPDATE ON {$boms}
             FOR EACH ROW BEGIN
                IF NEW.output_quantity_scaled <= 0 OR NEW.version <= 0
                   OR NEW.status NOT IN ('draft', 'active', 'retired') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid BOM header';
                END IF;
                IF OLD.status IN ('active', 'retired')
                   AND (NEW.code <> OLD.code OR NEW.name <> OLD.name OR NEW.version <> OLD.version
                        OR NEW.output_product_id <> OLD.output_product_id
                        OR NEW.output_quantity_scaled <> OLD.output_quantity_scaled
                        OR NOT (NEW.effective_from <=> OLD.effective_from)
                        OR NOT (NEW.effective_to <=> OLD.effective_to)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active or retired BOM structure is immutable';
                END IF;
                IF OLD.status = 'retired' AND NEW.status <> 'retired' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retired BOM cannot be reactivated';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[2]} BEFORE INSERT ON {$components}
             FOR EACH ROW BEGIN
                DECLARE parent_status varchar(20);
                DECLARE output_product bigint(20) unsigned;
                SELECT status, output_product_id INTO parent_status, output_product
                  FROM {$boms} WHERE id = NEW.bom_id;
                IF parent_status IS NULL OR parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Components can be added only to draft BOMs';
                END IF;
                IF NEW.product_id = output_product OR NEW.quantity_scaled <= 0
                   OR NEW.waste_basis_points > 10000
                   OR NEW.component_type NOT IN ('raw_material', 'packaging') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid BOM component';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[3]} BEFORE UPDATE ON {$components}
             FOR EACH ROW BEGIN
                DECLARE parent_status varchar(20);
                SELECT status INTO parent_status FROM {$boms} WHERE id = OLD.bom_id;
                IF parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Components of active or retired BOMs are immutable';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[4]} BEFORE DELETE ON {$components}
             FOR EACH ROW BEGIN
                DECLARE parent_status varchar(20);
                SELECT status INTO parent_status FROM {$boms} WHERE id = OLD.bom_id;
                IF parent_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Components of active or retired BOMs are immutable';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[5]} BEFORE INSERT ON {$orders}
             FOR EACH ROW BEGIN
                IF NEW.output_quantity_scaled <= 0 OR NEW.status <> 'processing' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid production order';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[6]} BEFORE UPDATE ON {$orders}
             FOR EACH ROW BEGIN
                IF OLD.status IN ('completed', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed production orders are immutable';
                END IF;
                IF OLD.status = 'processing' AND NEW.status NOT IN ('processing', 'completed', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid production order transition';
                END IF;
                IF NEW.status = 'completed'
                   AND (NEW.material_cost_irr IS NULL OR NEW.waste_cost_irr IS NULL
                        OR NEW.total_cost_irr IS NULL OR NEW.unit_cost_irr IS NULL
                        OR NEW.completed_by IS NULL OR NEW.completed_at IS NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed production order is missing costing data';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[7]} BEFORE INSERT ON {$consumptions}
             FOR EACH ROW BEGIN
                IF NEW.standard_quantity_scaled < 0 OR NEW.waste_quantity_scaled < 0
                   OR NEW.total_quantity_scaled <= 0
                   OR NEW.standard_quantity_scaled + NEW.waste_quantity_scaled <> NEW.total_quantity_scaled THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid production consumption';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[8]} BEFORE UPDATE ON {$consumptions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Production consumptions are immutable'",
            "CREATE TRIGGER {$triggers[9]} BEFORE DELETE ON {$consumptions}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Production consumptions are immutable'",
            "CREATE TRIGGER {$triggers[10]} BEFORE INSERT ON {$outputs}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled <= 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Production output must be positive';
                END IF;
             END",
            "CREATE TRIGGER {$triggers[11]} BEFORE UPDATE ON {$outputs}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Production outputs are immutable'",
            "CREATE TRIGGER {$triggers[12]} BEFORE DELETE ON {$outputs}
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Production outputs are immutable'",
            "CREATE TRIGGER {$triggers[13]} BEFORE INSERT ON {$movements}
             FOR EACH ROW BEGIN
                IF NEW.quantity_scaled = 0
                   OR (NEW.type IN ('receipt', 'transfer_in', 'adjustment_in', 'production_receipt')
                       AND NEW.quantity_scaled < 0)
                   OR (NEW.type IN ('issue', 'transfer_out', 'adjustment_out', 'production_issue', 'production_waste')
                       AND NEW.quantity_scaled > 0) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid stock movement direction';
                END IF;
             END",
        ];

        foreach ($queries as $query) {
            if ($wpdb->query($query) === false) {
                throw new RuntimeException('Unable to install manufacturing database protection: ' . $wpdb->last_error);
            }
        }
    }
}
