<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use RuntimeException;

trait WpdbLogisticsShipmentStorage
{
    public function createShipment(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_shipments';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                throw new LogisticsDomainException('Shipment idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }

        $packageCount = 0;
        $totalWeight = 0;
        $volumetricWeight = 0;
        foreach ($data['packages'] as $package) {
            $packageCount += (int) $package['quantity'];
            $totalWeight += (int) $package['total_weight_grams'];
            $volumetricWeight += (int) $package['volumetric_weight_grams'];
        }
        $now = current_time('mysql', true);
        $shipmentId = $this->insert('rishe_shipments', [
            'public_id' => wp_generate_uuid4(),
            'sales_order_id' => $data['sales_order_id'],
            'carrier_id' => null,
            'selected_quote_id' => null,
            'status' => $data['status'],
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'service_code' => null,
            'external_shipment_id' => null,
            'tracking_number' => null,
            'label_url' => null,
            'sender_json' => wp_json_encode($data['sender'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'recipient_json' => wp_json_encode($data['recipient'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'declared_value_irr' => $data['declared_value_irr'],
            'charged_shipping_irr' => $data['charged_shipping_irr'],
            'quoted_cost_irr' => 0,
            'actual_cost_irr' => 0,
            'settled_cost_irr' => 0,
            'cost_variance_irr' => -1 * (int) $data['charged_shipping_irr'],
            'cod_amount_irr' => $data['cod_amount_irr'],
            'package_count' => $packageCount,
            'total_weight_grams' => $totalWeight,
            'volumetric_weight_grams' => $volumetricWeight,
            'notes' => $data['notes'],
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s',
        ], 'shipment');

        foreach ($data['packages'] as $package) {
            $this->insert('rishe_shipment_packages', [
                'shipment_id' => $shipmentId,
                'sequence_no' => $package['sequence'],
                'weight_grams' => $package['weight_grams'],
                'length_mm' => $package['length_mm'],
                'width_mm' => $package['width_mm'],
                'height_mm' => $package['height_mm'],
                'quantity' => $package['quantity'],
                'total_weight_grams' => $package['total_weight_grams'],
                'volumetric_weight_grams' => $package['volumetric_weight_grams'],
                'contents' => $package['contents'],
                'created_at' => $now,
            ], ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s'], 'shipment package');
        }

        return ['id' => $shipmentId, 'idempotent' => false];
    }

    public function shipment(int $shipmentId): ?array
    {
        $row = $this->row('rishe_shipments', $shipmentId);

        return $row === null ? null : $this->hydrateShipment($row);
    }

    public function shipmentForUpdate(int $shipmentId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_shipments';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $shipmentId
        ), ARRAY_A);

        return is_array($row) ? $this->hydrateShipment($row) : null;
    }

    public function shipmentByCarrierReference(int $carrierId, string $reference): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_shipments';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE carrier_id = %d AND (external_shipment_id = %s OR tracking_number = %s)
             LIMIT 1 FOR UPDATE",
            $carrierId,
            $reference,
            $reference
        ), ARRAY_A);

        return is_array($row) ? $this->hydrateShipment($row) : null;
    }

    public function shipments(array $filters): array
    {
        return array_map([$this, 'formatShipment'], $this->simpleList(
            'rishe_shipments',
            $filters,
            ['sales_order_id', 'carrier_id', 'status', 'tracking_number']
        ));
    }

    public function recordQuote(int $shipmentId, array $quote): int
    {
        return $this->insert('rishe_shipment_quotes', [
            'public_id' => wp_generate_uuid4(),
            'shipment_id' => $shipmentId,
            'carrier_id' => $quote['carrier_id'],
            'service_code' => $quote['service_code'],
            'service_name' => $quote['service_name'],
            'amount_irr' => $quote['amount_irr'],
            'currency' => $quote['currency'],
            'estimated_days' => $quote['estimated_days'],
            'expires_at' => $quote['expires_at'],
            'raw_hash' => $quote['raw_hash'],
            'created_by' => $quote['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s'], 'shipment quote');
    }

    public function selectQuote(int $shipmentId, int $carrierId, int $quoteId, int $quotedCostIrr): void
    {
        global $wpdb;

        $quote = $this->row('rishe_shipment_quotes', $quoteId);
        if ($quote === null || (int) $quote['shipment_id'] !== $shipmentId || (int) $quote['carrier_id'] !== $carrierId) {
            throw new RuntimeException('Shipment quote does not belong to shipment and carrier.');
        }
        $updated = $wpdb->update($wpdb->prefix . 'rishe_shipments', [
            'carrier_id' => $carrierId,
            'selected_quote_id' => $quoteId,
            'service_code' => $quote['service_code'],
            'quoted_cost_irr' => $quotedCostIrr,
            'status' => 'quoted',
            'updated_at' => current_time('mysql', true),
        ], ['id' => $shipmentId], ['%d', '%d', '%s', '%d', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to select shipment quote.');
        }
    }

    public function recordBooking(int $shipmentId, int $carrierId, array $booking): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_shipments', [
            'carrier_id' => $carrierId,
            'service_code' => $booking['service_code'],
            'external_shipment_id' => $booking['external_shipment_id'],
            'tracking_number' => $booking['tracking_number'],
            'label_url' => $booking['label_url'],
            'status' => $booking['status'],
            'booked_at' => $booking['booked_at'],
            'updated_at' => current_time('mysql', true),
        ], ['id' => $shipmentId], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to record carrier booking.');
        }
    }

    public function updateShipmentStatus(int $shipmentId, string $status, ?string $occurredAt = null): void
    {
        global $wpdb;

        $data = ['status' => $status, 'updated_at' => current_time('mysql', true)];
        $formats = ['%s', '%s'];
        $timestampField = match ($status) {
            'in_transit' => 'in_transit_at',
            'delivered' => 'delivered_at',
            'cancelled' => 'cancelled_at',
            'returned' => 'returned_at',
            default => null,
        };
        if ($timestampField !== null && $occurredAt !== null) {
            $data[$timestampField] = $occurredAt;
            $formats[] = '%s';
        }
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_shipments',
            $data,
            ['id' => $shipmentId],
            $formats,
            ['%d']
        );
        if ($updated === false) {
            throw new RuntimeException('Unable to update shipment status: ' . $wpdb->last_error);
        }
    }

    /** @return array<string, mixed> */
    private function hydrateShipment(array $row): array
    {
        global $wpdb;

        $shipment = $this->formatShipment($row);
        $id = (int) $shipment['id'];
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_shipment_packages WHERE shipment_id = %d ORDER BY sequence_no",
            $id
        ), ARRAY_A);
        $quotes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_shipment_quotes WHERE shipment_id = %d ORDER BY id DESC",
            $id
        ), ARRAY_A);
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_shipment_tracking_events
             WHERE shipment_id = %d ORDER BY occurred_at, id",
            $id
        ), ARRAY_A);
        $costs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_shipment_costs WHERE shipment_id = %d ORDER BY incurred_at, id",
            $id
        ), ARRAY_A);
        $settlements = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_logistics_settlements WHERE shipment_id = %d ORDER BY id",
            $id
        ), ARRAY_A);
        $shipment['packages'] = array_map(fn (array $item): array => $this->castIntegers($item, [
            'id', 'shipment_id', 'sequence_no', 'weight_grams', 'length_mm', 'width_mm', 'height_mm',
            'quantity', 'total_weight_grams', 'volumetric_weight_grams',
        ]), is_array($packages) ? $packages : []);
        $shipment['quotes'] = array_map(fn (array $item): array => $this->castIntegers($item, [
            'id', 'shipment_id', 'carrier_id', 'amount_irr', 'estimated_days', 'created_by',
        ]), is_array($quotes) ? $quotes : []);
        $shipment['tracking_events'] = array_map(fn (array $item): array => $this->castIntegers($item, [
            'id', 'shipment_id', 'carrier_id',
        ]), is_array($events) ? $events : []);
        $shipment['costs'] = array_map(fn (array $item): array => $this->castIntegers($item, [
            'id', 'shipment_id', 'carrier_id', 'amount_irr', 'created_by',
        ]), is_array($costs) ? $costs : []);
        $shipment['settlements'] = array_map(fn (array $item): array => $this->castIntegers($item, [
            'id', 'shipment_id', 'treasury_transaction_id', 'amount_irr', 'accounting_voucher_id',
            'accounting_voucher_number', 'settled_by',
        ]), is_array($settlements) ? $settlements : []);
        if ($shipment['carrier_id'] !== null) {
            $carrier = $this->carrier((int) $shipment['carrier_id']);
            $shipment['carrier_code'] = $carrier['code'] ?? null;
            $shipment['carrier_name'] = $carrier['name'] ?? null;
            $shipment['carrier_shipping_expense_subsidiary_ledger_id'] =
                $carrier['shipping_expense_subsidiary_ledger_id'] ?? null;
        }

        return $shipment;
    }
}
