<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use RuntimeException;

trait WpdbLogisticsTrackingCostStorage
{
    public function appendTrackingEvent(int $shipmentId, int $carrierId, array $event): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_shipment_tracking_events';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_hash = %s OR (carrier_id = %d AND external_event_id = %s)
             LIMIT 1 FOR UPDATE",
            $event['event_hash'],
            $carrierId,
            $event['external_event_id']
        ), ARRAY_A);
        if (is_array($existing)) {
            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $id = $this->insert('rishe_shipment_tracking_events', [
            'public_id' => wp_generate_uuid4(),
            'shipment_id' => $shipmentId,
            'carrier_id' => $carrierId,
            'external_event_id' => $event['external_event_id'],
            'status' => $event['status'],
            'occurred_at' => $event['occurred_at'],
            'description' => $event['description'],
            'location' => $event['location'],
            'raw_hash' => $event['raw_hash'],
            'event_hash' => $event['event_hash'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], 'tracking event');

        return ['id' => $id, 'idempotent' => false];
    }

    public function recordCost(int $shipmentId, array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_shipment_costs';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE carrier_id = %d AND external_cost_id = %s FOR UPDATE",
            $data['carrier_id'],
            $data['external_cost_id']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((int) $existing['shipment_id'] !== $shipmentId || (int) $existing['amount_irr'] !== $data['amount_irr']) {
                throw new LogisticsDomainException('Carrier cost reference was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $id = $this->insert('rishe_shipment_costs', [
            'public_id' => wp_generate_uuid4(),
            'shipment_id' => $shipmentId,
            'carrier_id' => $data['carrier_id'],
            'cost_type' => $data['cost_type'],
            'amount_irr' => $data['amount_irr'],
            'external_cost_id' => $data['external_cost_id'],
            'invoice_reference' => $data['invoice_reference'],
            'incurred_at' => $data['incurred_at'],
            'description' => $data['description'],
            'raw_hash' => $data['raw_hash'],
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], 'shipment cost');

        $shipments = $wpdb->prefix . 'rishe_shipments';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$shipments}
             SET actual_cost_irr = actual_cost_irr + %d,
                 cost_variance_irr = CAST(actual_cost_irr + %d AS SIGNED) - CAST(charged_shipping_irr AS SIGNED),
                 updated_at = %s
             WHERE id = %d",
            $data['amount_irr'],
            $data['amount_irr'],
            current_time('mysql', true),
            $shipmentId
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update shipment actual cost.');
        }

        return ['id' => $id, 'idempotent' => false];
    }

    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_logistics_settlements';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE treasury_transaction_id = %d FOR UPDATE",
            $treasuryTransactionId
        ), ARRAY_A);

        return is_array($row) ? $this->castIntegers($row, [
            'id', 'shipment_id', 'treasury_transaction_id', 'amount_irr', 'accounting_voucher_id',
            'accounting_voucher_number', 'settled_by',
        ]) : null;
    }

    public function recordSettlement(
        int $shipmentId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        global $wpdb;

        $existing = $this->settlementByTreasuryTransaction($treasuryTransactionId);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $now = current_time('mysql', true);
        $id = $this->insert('rishe_logistics_settlements', [
            'public_id' => wp_generate_uuid4(),
            'shipment_id' => $shipmentId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
            'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
            'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
            'settled_by' => $actorUserId,
            'settled_at' => $now,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s'], 'logistics settlement');
        $shipments = $wpdb->prefix . 'rishe_shipments';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$shipments}
             SET settled_cost_irr = settled_cost_irr + %d, updated_at = %s
             WHERE id = %d AND settled_cost_irr + %d <= actual_cost_irr",
            $amountIrr,
            $now,
            $shipmentId,
            $amountIrr
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update settled carrier cost.');
        }

        return ['id' => $id, 'idempotent' => false];
    }
}
