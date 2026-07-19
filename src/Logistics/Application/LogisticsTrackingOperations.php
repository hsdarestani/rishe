<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\ShipmentStatus;
use Rishe\Logistics\Domain\TrackingEvent;
use RuntimeException;

trait LogisticsTrackingOperations
{
    /** @return array<string, mixed> */
    public function refreshTracking(int $shipmentId, int $actorUserId): array
    {
        $this->actor($actorUserId);

        return $this->transactions->run(function () use ($shipmentId): array {
            $shipment = $this->lockedShipment($shipmentId);
            if (($shipment['carrier_id'] ?? null) === null || ($shipment['external_shipment_id'] ?? null) === null) {
                throw new LogisticsDomainException('Shipment has not been booked with a carrier.');
            }
            $carrier = $this->requireCarrier((int) $shipment['carrier_id']);
            $events = $this->gateways->gateway($carrier)->track($shipment);
            $recorded = 0;
            foreach ($events as $event) {
                if (!is_array($event)) {
                    throw new LogisticsDomainException('Carrier tracking event must be an object.');
                }
                $result = $this->recordTrackingEvent($shipment, $carrier, $event);
                if (!$result['idempotent']) {
                    ++$recorded;
                }
                $shipment = $this->lockedShipment((int) $shipment['id']);
            }
            $this->audit->record(
                'logistics.tracking.refreshed',
                'shipment',
                (string) $shipment['id'],
                ['received_events' => count($events), 'recorded_events' => $recorded],
                $shipment['correlation_id'] ?? null
            );

            return $this->requireShipment((int) $shipment['id']);
        });
    }

    /** @return array{shipment_id: int, recorded_events: int} */
    public function processWebhook(string $carrierCode, string $rawBody, string $signature): array
    {
        $carrier = $this->repository->carrierByCode($this->code($carrierCode, 'carrier_code'));
        if ($carrier === null || !(bool) ($carrier['is_active'] ?? false)) {
            throw new RuntimeException('Carrier not found.');
        }
        if (!$this->webhooks->verify($carrier, $rawBody, $signature)) {
            throw new LogisticsDomainException('Carrier webhook signature is invalid.');
        }
        $events = $this->gateways->gateway($carrier)->parseWebhook($rawBody);
        if ($events === []) {
            throw new LogisticsDomainException('Carrier webhook contains no tracking events.');
        }

        return $this->transactions->run(function () use ($carrier, $events): array {
            $shipmentId = null;
            $recorded = 0;
            foreach ($events as $event) {
                if (!is_array($event)) {
                    throw new LogisticsDomainException('Carrier webhook event must be an object.');
                }
                $reference = trim((string) ($event['external_shipment_id'] ?? $event['tracking_number'] ?? ''));
                if ($reference === '') {
                    throw new LogisticsDomainException('Webhook event lacks shipment reference.');
                }
                $shipment = $this->repository->shipmentByCarrierReference((int) $carrier['id'], $reference);
                if ($shipment === null) {
                    throw new RuntimeException('Shipment referenced by carrier webhook was not found.');
                }
                if ($shipmentId !== null && $shipmentId !== (int) $shipment['id']) {
                    throw new LogisticsDomainException('One webhook request cannot mutate multiple shipments.');
                }
                $shipmentId = (int) $shipment['id'];
                $result = $this->recordTrackingEvent($shipment, $carrier, $event);
                if (!$result['idempotent']) {
                    ++$recorded;
                }
            }
            $this->audit->record(
                'logistics.webhook.processed',
                'shipment',
                (string) $shipmentId,
                ['carrier_id' => (int) $carrier['id'], 'recorded_events' => $recorded]
            );

            return ['shipment_id' => (int) $shipmentId, 'recorded_events' => $recorded];
        });
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $carrier
     * @param array<string, mixed> $event
     * @return array{id: int, idempotent: bool}
     */
    private function recordTrackingEvent(array $shipment, array $carrier, array $event): array
    {
        $current = $this->shipmentStatus($shipment);
        $next = ShipmentStatus::tryFrom(strtolower(trim((string) ($event['status'] ?? ''))));
        if ($next === null) {
            throw new LogisticsDomainException('Carrier tracking status is not mapped to a Rishe shipment status.');
        }
        $current->assertTransitionTo($next);
        $occurredAt = $this->dateTime($event['occurred_at'] ?? gmdate('c'), 'tracking.occurred_at');
        $externalEventId = trim((string) ($event['external_event_id'] ?? ''));
        if ($externalEventId === '') {
            $externalEventId = hash('sha256', json_encode([
                $shipment['id'],
                $next->value,
                $occurredAt,
                $event['description'] ?? null,
                $event['location'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $tracking = new TrackingEvent(
            $externalEventId,
            $next,
            $occurredAt,
            $this->nullableText($event['description'] ?? null, 500),
            $this->nullableText($event['location'] ?? null, 191),
            isset($event['raw_hash']) ? (string) $event['raw_hash'] : null
        );
        $payload = [
            'external_event_id' => $tracking->externalEventId,
            'status' => $tracking->status->value,
            'occurred_at' => (new \DateTimeImmutable($tracking->occurredAt))->format('Y-m-d H:i:s'),
            'description' => $tracking->description,
            'location' => $tracking->location,
            'raw_hash' => $tracking->rawHash,
            'event_hash' => hash('sha256', json_encode([
                (int) $shipment['id'],
                (int) $carrier['id'],
                $tracking->externalEventId,
                $tracking->status->value,
                $tracking->occurredAt,
            ], JSON_THROW_ON_ERROR)),
        ];
        $result = $this->repository->appendTrackingEvent(
            (int) $shipment['id'],
            (int) $carrier['id'],
            $payload
        );
        if (!$result['idempotent']) {
            $this->repository->updateShipmentStatus((int) $shipment['id'], $next->value, $payload['occurred_at']);
            $this->audit->record(
                'logistics.tracking.event_recorded',
                'shipment',
                (string) $shipment['id'],
                [
                    'carrier_id' => (int) $carrier['id'],
                    'event_id' => (int) $result['id'],
                    'status' => $next->value,
                ],
                $shipment['correlation_id'] ?? null
            );
        }

        return $result;
    }
}
