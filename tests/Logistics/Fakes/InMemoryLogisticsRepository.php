<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\LogisticsRepository;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

final class InMemoryLogisticsRepository implements LogisticsRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $carriers = [];
    /** @var array<int, array<string, mixed>> */
    public array $shipments = [];
    /** @var array<int, array<string, mixed>> */
    public array $settlements = [];
    private int $carrierSequence = 0;
    private int $shipmentSequence = 0;
    private int $quoteSequence = 0;
    private int $eventSequence = 0;
    private int $costSequence = 0;
    private int $settlementSequence = 0;

    public function upsertCarrier(array $data): array
    {
        foreach ($this->carriers as $id => $carrier) {
            if ($carrier['code'] === $data['code']) {
                $this->carriers[$id] = array_merge($carrier, $data);

                return ['id' => $id, 'created' => false];
            }
        }
        $id = ++$this->carrierSequence;
        $this->carriers[$id] = $data + ['id' => $id, 'public_id' => 'carrier-' . $id, 'is_active' => true];

        return ['id' => $id, 'created' => true];
    }

    public function carrier(int $carrierId): ?array
    {
        return $this->carriers[$carrierId] ?? null;
    }

    public function carrierByCode(string $code): ?array
    {
        foreach ($this->carriers as $carrier) {
            if ($carrier['code'] === $code) {
                return $carrier;
            }
        }

        return null;
    }

    public function carriers(array $filters): array
    {
        return array_values($this->carriers);
    }

    public function salesOrder(int $salesOrderId): ?array
    {
        return $salesOrderId === 1 ? [
            'id' => 1,
            'status' => 'paid',
            'shipping_irr' => 20000,
            'total_irr' => 500000,
        ] : null;
    }

    public function createShipment(array $data): array
    {
        foreach ($this->shipments as $shipment) {
            if ($shipment['idempotency_key'] === $data['idempotency_key']) {
                if ($shipment['payload_hash'] !== $data['payload_hash']) {
                    throw new LogisticsDomainException('Shipment idempotency key was reused.');
                }

                return ['id' => $shipment['id'], 'idempotent' => true];
            }
        }
        $id = ++$this->shipmentSequence;
        $this->shipments[$id] = array_merge($data, [
            'id' => $id,
            'public_id' => 'shipment-' . $id,
            'carrier_id' => null,
            'selected_quote_id' => null,
            'service_code' => null,
            'external_shipment_id' => null,
            'tracking_number' => null,
            'label_url' => null,
            'quoted_cost_irr' => 0,
            'actual_cost_irr' => 0,
            'settled_cost_irr' => 0,
            'cost_variance_irr' => -1 * $data['charged_shipping_irr'],
            'tracking_events' => [],
            'costs' => [],
            'settlements' => [],
        ]);

        return ['id' => $id, 'idempotent' => false];
    }

    public function shipment(int $shipmentId): ?array
    {
        $shipment = $this->shipments[$shipmentId] ?? null;
        if ($shipment !== null) {
            $shipment['unsettled_cost_irr'] = $shipment['actual_cost_irr'] - $shipment['settled_cost_irr'];
        }

        return $shipment;
    }

    public function shipmentForUpdate(int $shipmentId): ?array
    {
        return $this->shipment($shipmentId);
    }

    public function shipmentByCarrierReference(int $carrierId, string $reference): ?array
    {
        foreach ($this->shipments as $shipment) {
            if (
                $shipment['carrier_id'] === $carrierId
                && in_array($reference, [$shipment['external_shipment_id'], $shipment['tracking_number']], true)
            ) {
                return $shipment;
            }
        }

        return null;
    }

    public function shipments(array $filters): array
    {
        return array_values($this->shipments);
    }

    public function recordQuote(int $shipmentId, array $quote): int
    {
        $id = ++$this->quoteSequence;
        $this->shipments[$shipmentId]['quotes'][] = $quote + ['id' => $id];

        return $id;
    }

    public function selectQuote(int $shipmentId, int $carrierId, int $quoteId, int $quotedCostIrr): void
    {
        $this->shipments[$shipmentId]['carrier_id'] = $carrierId;
        $this->shipments[$shipmentId]['selected_quote_id'] = $quoteId;
        $this->shipments[$shipmentId]['service_code'] = 'express';
        $this->shipments[$shipmentId]['quoted_cost_irr'] = $quotedCostIrr;
        $this->shipments[$shipmentId]['status'] = 'quoted';
    }

    public function recordBooking(int $shipmentId, int $carrierId, array $booking): void
    {
        $this->shipments[$shipmentId]['carrier_id'] = $carrierId;
        $this->shipments[$shipmentId]['service_code'] = $booking['service_code'];
        $this->shipments[$shipmentId]['external_shipment_id'] = $booking['external_shipment_id'];
        $this->shipments[$shipmentId]['tracking_number'] = $booking['tracking_number'];
        $this->shipments[$shipmentId]['label_url'] = $booking['label_url'];
        $this->shipments[$shipmentId]['status'] = $booking['status'];
    }

    public function updateShipmentStatus(int $shipmentId, string $status, ?string $occurredAt = null): void
    {
        $this->shipments[$shipmentId]['status'] = $status;
    }

    public function appendTrackingEvent(int $shipmentId, int $carrierId, array $event): array
    {
        foreach ($this->shipments[$shipmentId]['tracking_events'] as $existing) {
            if ($existing['event_hash'] === $event['event_hash']) {
                return ['id' => $existing['id'], 'idempotent' => true];
            }
        }
        $id = ++$this->eventSequence;
        $this->shipments[$shipmentId]['tracking_events'][] = $event + ['id' => $id];

        return ['id' => $id, 'idempotent' => false];
    }

    public function recordCost(int $shipmentId, array $data): array
    {
        foreach ($this->shipments[$shipmentId]['costs'] as $cost) {
            if ($cost['external_cost_id'] === $data['external_cost_id']) {
                return ['id' => $cost['id'], 'idempotent' => true];
            }
        }
        $id = ++$this->costSequence;
        $this->shipments[$shipmentId]['costs'][] = $data + ['id' => $id];
        $this->shipments[$shipmentId]['actual_cost_irr'] += $data['amount_irr'];
        $this->shipments[$shipmentId]['cost_variance_irr'] =
            $this->shipments[$shipmentId]['actual_cost_irr'] - $this->shipments[$shipmentId]['charged_shipping_irr'];

        return ['id' => $id, 'idempotent' => false];
    }

    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        foreach ($this->settlements as $settlement) {
            if ($settlement['treasury_transaction_id'] === $treasuryTransactionId) {
                return $settlement;
            }
        }

        return null;
    }

    public function recordSettlement(
        int $shipmentId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        $id = ++$this->settlementSequence;
        $settlement = [
            'id' => $id,
            'shipment_id' => $shipmentId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
        ];
        $this->settlements[$id] = $settlement;
        $this->shipments[$shipmentId]['settlements'][] = $settlement;
        $this->shipments[$shipmentId]['settled_cost_irr'] += $amountIrr;

        return ['id' => $id, 'idempotent' => false];
    }
}
