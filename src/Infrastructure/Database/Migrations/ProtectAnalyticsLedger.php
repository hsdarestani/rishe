<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectAnalyticsLedger implements Migration
{
    public function id(): string
    {
        return '2026071924_protect_analytics_ledger';
    }

    public function up(): void
    {
        global $wpdb;
        $events = $wpdb->prefix . 'rishe_business_events';
        $attribution = $wpdb->prefix . 'rishe_order_attribution';
        $targets = $wpdb->prefix . 'rishe_analytics_targets';
        $prices = $wpdb->prefix . 'rishe_price_history';
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->prefix);
        if ($prefix === null) {
            throw new RuntimeException('Invalid WordPress database prefix.');
        }
        $triggers = [
            "{$prefix}rishe_business_events_no_update" => "BEFORE UPDATE ON {$events} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business events are append-only'; END",
            "{$prefix}rishe_business_events_no_delete" => "BEFORE DELETE ON {$events} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Business events cannot be deleted'; END",
            "{$prefix}rishe_order_attribution_no_update" => "BEFORE UPDATE ON {$attribution} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order attribution is immutable'; END",
            "{$prefix}rishe_order_attribution_no_delete" => "BEFORE DELETE ON {$attribution} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order attribution cannot be deleted'; END",
            "{$prefix}rishe_analytics_targets_no_update" => "BEFORE UPDATE ON {$targets} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Analytics targets are immutable; create a replacement target'; END",
            "{$prefix}rishe_analytics_targets_no_delete" => "BEFORE DELETE ON {$targets} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Analytics targets cannot be deleted'; END",
            "{$prefix}rishe_price_history_guard" => "BEFORE UPDATE ON {$prices} FOR EACH ROW BEGIN
                IF NOT (OLD.price_key <=> NEW.price_key)
                   OR NOT (OLD.product_id <=> NEW.product_id)
                   OR NOT (OLD.channel <=> NEW.channel)
                   OR NOT (OLD.purchase_price_irr <=> NEW.purchase_price_irr)
                   OR NOT (OLD.cogs_irr <=> NEW.cogs_irr)
                   OR NOT (OLD.selling_price_irr <=> NEW.selling_price_irr)
                   OR NOT (OLD.effective_from <=> NEW.effective_from)
                   OR NOT (OLD.reason <=> NEW.reason)
                   OR NOT (OLD.actor_user_id <=> NEW.actor_user_id)
                   OR NOT (OLD.created_at <=> NEW.created_at)
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Price history commercial fields are immutable';
                END IF;
                IF OLD.effective_to IS NOT NULL AND NOT (OLD.effective_to <=> NEW.effective_to)
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed price interval cannot change'; END IF;
                IF OLD.effective_to IS NULL AND NEW.effective_to IS NOT NULL AND NEW.effective_to < OLD.effective_from
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Price interval end cannot precede start'; END IF;
            END",
            "{$prefix}rishe_price_history_no_delete" => "BEFORE DELETE ON {$prices} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Price history cannot be deleted'; END",
        ];
        foreach ($triggers as $name => $definition) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$definition}") === false) {
                throw new RuntimeException('Unable to create analytics protection trigger: ' . $name . '. ' . $wpdb->last_error);
            }
        }
    }
}
