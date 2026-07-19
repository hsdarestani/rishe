<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectLogisticsLedger implements Migration
{
    public function id(): string
    {
        return '2026071918_protect_logistics_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $shipments = $wpdb->prefix . 'rishe_shipments';
        $packages = $wpdb->prefix . 'rishe_shipment_packages';
        $quotes = $wpdb->prefix . 'rishe_shipment_quotes';
        $events = $wpdb->prefix . 'rishe_shipment_tracking_events';
        $costs = $wpdb->prefix . 'rishe_shipment_costs';
        $settlements = $wpdb->prefix . 'rishe_logistics_settlements';
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);

        $triggers = [
            "{$prefix}rishe_shipment_guard_update" => "
                BEFORE UPDATE ON {$shipments} FOR EACH ROW
                BEGIN
                    IF NEW.sales_order_id <> OLD.sales_order_id OR NEW.payload_hash <> OLD.payload_hash OR
                       NEW.sender_json <> OLD.sender_json OR NEW.recipient_json <> OLD.recipient_json OR
                       NEW.declared_value_irr <> OLD.declared_value_irr OR
                       NEW.charged_shipping_irr <> OLD.charged_shipping_irr OR
                       NEW.cod_amount_irr <> OLD.cod_amount_irr OR NEW.package_count <> OLD.package_count OR
                       NEW.total_weight_grams <> OLD.total_weight_grams OR
                       NEW.volumetric_weight_grams <> OLD.volumetric_weight_grams THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment commercial snapshot is immutable';
                    END IF;
                    IF OLD.external_shipment_id IS NOT NULL AND NEW.external_shipment_id <> OLD.external_shipment_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carrier shipment id is immutable';
                    END IF;
                    IF OLD.tracking_number IS NOT NULL AND NEW.tracking_number <> OLD.tracking_number THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tracking number is immutable';
                    END IF;
                    IF NEW.actual_cost_irr < OLD.actual_cost_irr OR NEW.settled_cost_irr < OLD.settled_cost_irr OR
                       NEW.settled_cost_irr > NEW.actual_cost_irr OR
                       NEW.cost_variance_irr <> CAST(NEW.actual_cost_irr AS SIGNED) - CAST(NEW.charged_shipping_irr AS SIGNED) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid shipment cost aggregates';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'draft' AND NEW.status IN ('quoted', 'booked', 'label_ready', 'cancelled')) OR
                        (OLD.status = 'quoted' AND NEW.status IN ('booked', 'label_ready', 'cancelled')) OR
                        (OLD.status = 'booked' AND NEW.status IN ('label_ready', 'in_transit', 'exception', 'cancelled')) OR
                        (OLD.status = 'label_ready' AND NEW.status IN ('in_transit', 'exception', 'cancelled')) OR
                        (OLD.status = 'in_transit' AND NEW.status IN ('delivered', 'exception', 'returned')) OR
                        (OLD.status = 'exception' AND NEW.status IN ('in_transit', 'delivered', 'returned', 'cancelled')) OR
                        (OLD.status = 'delivered' AND NEW.status = 'returned')
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid shipment lifecycle transition';
                    END IF;
                END",
            "{$prefix}rishe_shipment_guard_delete" => "
                BEFORE DELETE ON {$shipments} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipments cannot be deleted';
                END",
            "{$prefix}rishe_package_guard_update" => "
                BEFORE UPDATE ON {$packages} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment packages are immutable';
                END",
            "{$prefix}rishe_package_guard_delete" => "
                BEFORE DELETE ON {$packages} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment packages are immutable';
                END",
            "{$prefix}rishe_quote_guard_update" => "
                BEFORE UPDATE ON {$quotes} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment quotes are immutable';
                END",
            "{$prefix}rishe_quote_guard_delete" => "
                BEFORE DELETE ON {$quotes} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment quotes are immutable';
                END",
            "{$prefix}rishe_tracking_guard_update" => "
                BEFORE UPDATE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tracking events are append-only';
                END",
            "{$prefix}rishe_tracking_guard_delete" => "
                BEFORE DELETE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tracking events are append-only';
                END",
            "{$prefix}rishe_cost_guard_update" => "
                BEFORE UPDATE ON {$costs} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment costs are immutable';
                END",
            "{$prefix}rishe_cost_guard_delete" => "
                BEFORE DELETE ON {$costs} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shipment costs are immutable';
                END",
            "{$prefix}rishe_logistics_settlement_guard_update" => "
                BEFORE UPDATE ON {$settlements} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Logistics settlements are immutable';
                END",
            "{$prefix}rishe_logistics_settlement_guard_delete" => "
                BEFORE DELETE ON {$settlements} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Logistics settlements are immutable';
                END",
        ];

        foreach ($triggers as $name => $body) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
                throw new RuntimeException('Unable to create logistics trigger ' . $name . ': ' . $wpdb->last_error);
            }
        }
    }
}
