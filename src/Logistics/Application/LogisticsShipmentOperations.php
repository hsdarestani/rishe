<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\ShipmentStatus;
use RuntimeException;

trait LogisticsShipmentOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createShipment(array $data, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $salesOrderId = $this->optionalPositiveId($data['sales_order_id'] ?? null);
        $order = null;
        if ($salesOrderId !== null) {
            $order = $this->repository->salesOrder($salesOrderId);
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }
            if ((string) ($order['status'] ?? '') === 'cancelled') {
                throw new LogisticsDomainException('Cancelled sales order cannot be shipped.');
            }
        }

        $sender = $this->address($data['sender'] ?? null, 'sender');
        $recipient = $this->address($data['recipient'] ?? null, 'recipient');
        $packages = $this->packages($data['packages'] ?? null);
        $chargedShipping = $this->nonNegativeMoney(
            $data['charged_shipping_irr'] ?? ($order['shipping_irr'] ?? 0),
            'charged_shipping_irr'
        );
        $declaredValue = $this->nonNegativeMoney(
            $data['declared_value_irr'] ?? ($order['total_irr'] ?? 0),
            'declared_value_irr'
        );
        $idempotencyKey = $this->requiredText($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $commercialPayload = [
            'sales_order_id' => $salesOrderId,
            'sender' => $sender,
            'recipient' => $recipient,
            'packages' => $packages,
            'declared_value_irr' => $declaredValue,
            'charged_shipping_irr' => $chargedShipping,
            'cod_amount_irr' => $this->nonNegativeMoney($data['cod_amount_irr'] ?? 0, 'cod_amount_irr'),
        ];
        $payloadHash = hash(
            'sha256',
            json_encode($commercialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $this->transactions->run(function () use (
            $salesOrderId,
            $sender,
            $recipient,
            $packages,
            $declaredValue,
            $chargedShipping,
            $idempotencyKey,
            $payloadHash,
            $data,
            $actor
        ): array {
            $result = $this->repository->createShipment([
                'sales_order_id' => $salesOrderId,
                'status' => ShipmentStatus::DRAFT->value,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'sender' => $sender,
                'recipient' => $recipient,
                'packages' => $packages,
                'declared_value_irr' => $declaredValue,
                'charged_shipping_irr' => $chargedShipping,
                'cod_amount_irr' => $this->nonNegativeMoney($data['cod_amount_irr'] ?? 0, 'cod_amount_irr'),
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
                'actor_user_id' => $actor,
            ]);
            if (!$result['idempotent']) {
                $this->audit->record(
                    'logistics.shipment.created',
                    'shipment',
                    (string) $result['id'],
                    [
                        'sales_order_id' => $salesOrderId,
                        'package_count' => count($packages),
                        'charged_shipping_irr' => $chargedShipping,
                    ],
                    $this->nullableText($data['correlation_id'] ?? null, 64)
                );
            }

            return $this->requireShipment((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function quoteShipment(int $shipmentId, int $carrierId, ?string $serviceCode, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($shipmentId, $carrierId, $serviceCode, $actor): array {
            $shipment = $this->lockedShipment($shipmentId);
            $status = $this->shipmentStatus($shipment);
            $status->assertCanQuote();
            $carrier = $this->requireCarrier($this->positiveId($carrierId, 'carrier_id'));
            $shipment['requested_service_code'] = $this->nullableText($serviceCode, 100);
            $response = $this->gateways->gateway($carrier)->quote($shipment);
            $amount = $this->positiveMoney($response['amount_irr'] ?? null, 'quote.amount_irr');
            $expiresAt = isset($response['expires_at']) && $response['expires_at'] !== null
                ? $this->dateTime($response['expires_at'], 'quote.expires_at')
                : null;
            $quoteId = $this->repository->recordQuote((int) $shipment['id'], [
                'carrier_id' => (int) $carrier['id'],
                'service_code' => $this->requiredText(
                    $response['service_code'] ?? $serviceCode ?? 'default',
                    'quote.service_code',
                    100
                ),
                'service_name' => $this->nullableText($response['service_name'] ?? null, 191),
                'amount_irr' => $amount,
                'currency' => 'IRR',
                'estimated_days' => isset($response['estimated_days'])
                    ? $this->positiveInteger($response['estimated_days'], 'estimated_days')
                    : null,
                'expires_at' => $expiresAt,
                'raw_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
                'actor_user_id' => $actor,
            ]);
            $this->repository->selectQuote(
                (int) $shipment['id'],
                (int) $carrier['id'],
                $quoteId,
                $amount
            );
            $this->audit->record(
                'logistics.shipment.quoted',
                'shipment',
                (string) $shipment['id'],
                ['carrier_id' => (int) $carrier['id'], 'quote_id' => $quoteId, 'amount_irr' => $amount],
                $shipment['correlation_id'] ?? null
            );

            return $this->requireShipment((int) $shipment['id']);
        });
    }

    /** @return array<string, mixed> */
    public function bookShipment(
        int $shipmentId,
        ?int $carrierId,
        ?string $serviceCode,
        int $actorUserId
    ): array {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($shipmentId, $carrierId, $serviceCode, $actor): array {
            $shipment = $this->lockedShipment($shipmentId);
            $status = $this->shipmentStatus($shipment);
            $alreadyBookedStatuses = [
                ShipmentStatus::BOOKED,
                ShipmentStatus::LABEL_READY,
                ShipmentStatus::IN_TRANSIT,
                ShipmentStatus::DELIVERED,
            ];
            if (in_array($status, $alreadyBookedStatuses, true)) {
                return $this->requireShipment((int) $shipment['id']);
            }
            $status->assertCanBook();
            $resolvedCarrierId = $carrierId ?? ($shipment['carrier_id'] ?? null);
            $carrier = $this->requireCarrier($this->positiveId($resolvedCarrierId, 'carrier_id'));
            $shipment['requested_service_code'] = $this->nullableText(
                $serviceCode ?? ($shipment['selected_service_code'] ?? null),
                100
            );
            $response = $this->gateways->gateway($carrier)->book($shipment);
            $externalId = $this->requiredText(
                $response['external_shipment_id'] ?? null,
                'booking.external_shipment_id',
                191
            );
            $trackingNumber = $this->nullableText($response['tracking_number'] ?? null, 191);
            $labelUrl = $this->nullableText($response['label_url'] ?? null, 1000);
            $next = $labelUrl === null ? ShipmentStatus::BOOKED : ShipmentStatus::LABEL_READY;
            $status->assertTransitionTo($next);
            $this->repository->recordBooking((int) $shipment['id'], (int) $carrier['id'], [
                'external_shipment_id' => $externalId,
                'tracking_number' => $trackingNumber,
                'label_url' => $labelUrl,
                'service_code' => $this->requiredText(
                    $response['service_code'] ?? $shipment['requested_service_code'] ?? 'default',
                    'booking.service_code',
                    100
                ),
                'status' => $next->value,
                'booked_at' => gmdate('Y-m-d H:i:s'),
                'raw_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
                'actor_user_id' => $actor,
            ]);
            $this->audit->record(
                'logistics.shipment.booked',
                'shipment',
                (string) $shipment['id'],
                [
                    'carrier_id' => (int) $carrier['id'],
                    'external_shipment_id' => $externalId,
                    'tracking_number' => $trackingNumber,
                ],
                $shipment['correlation_id'] ?? null
            );

            return $this->requireShipment((int) $shipment['id']);
        });
    }

    /** @return array<string, mixed> */
    public function cancelShipment(int $shipmentId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($shipmentId, $actor): array {
            $shipment = $this->lockedShipment($shipmentId);
            $status = $this->shipmentStatus($shipment);
            if ($status === ShipmentStatus::CANCELLED) {
                return $this->requireShipment((int) $shipment['id']);
            }
            $status->assertCanCancel();
            if (($shipment['carrier_id'] ?? null) !== null) {
                $carrier = $this->requireCarrier((int) $shipment['carrier_id']);
                if (($shipment['external_shipment_id'] ?? null) !== null) {
                    $this->gateways->gateway($carrier)->cancel($shipment);
                }
            }
            $this->repository->updateShipmentStatus(
                (int) $shipment['id'],
                ShipmentStatus::CANCELLED->value,
                gmdate('Y-m-d H:i:s')
            );
            $this->audit->record(
                'logistics.shipment.cancelled',
                'shipment',
                (string) $shipment['id'],
                ['actor_user_id' => $actor],
                $shipment['correlation_id'] ?? null
            );

            return $this->requireShipment((int) $shipment['id']);
        });
    }

    /** @return array<string, mixed> */
    public function shipment(int $shipmentId): array
    {
        return $this->requireShipment($this->positiveId($shipmentId, 'shipment_id'));
    }

    /** @return list<array<string, mixed>> */
    public function shipments(array $filters = []): array
    {
        return $this->repository->shipments([
            'sales_order_id' => $this->optionalPositiveId($filters['sales_order_id'] ?? null),
            'carrier_id' => $this->optionalPositiveId($filters['carrier_id'] ?? null),
            'status' => $this->nullableText($filters['status'] ?? null, 30),
            'tracking_number' => $this->nullableText($filters['tracking_number'] ?? null, 191),
        ]);
    }

    /** @return array<string, mixed> */
    private function lockedShipment(int $shipmentId): array
    {
        $shipment = $this->repository->shipmentForUpdate($this->positiveId($shipmentId, 'shipment_id'));
        if ($shipment === null) {
            throw new RuntimeException('Shipment not found.');
        }

        return $shipment;
    }

    /** @return array<string, mixed> */
    private function requireShipment(int $shipmentId): array
    {
        $shipment = $this->repository->shipment($shipmentId);
        if ($shipment === null) {
            throw new RuntimeException('Shipment not found.');
        }

        return $shipment;
    }

    /** @param array<string, mixed> $shipment */
    private function shipmentStatus(array $shipment): ShipmentStatus
    {
        $status = ShipmentStatus::tryFrom((string) ($shipment['status'] ?? ''));
        if ($status === null) {
            throw new LogisticsDomainException('Shipment status is invalid.');
        }

        return $status;
    }
}
