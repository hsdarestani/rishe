<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\CarrierGateway;

final class InMemoryCarrierGateway implements CarrierGateway
{
    public function quote(array $shipment): array
    {
        return [
            'service_code' => 'express',
            'service_name' => 'Express',
            'amount_irr' => 25000,
            'estimated_days' => 1,
            'expires_at' => '2026-07-20 12:00:00',
        ];
    }

    public function book(array $shipment): array
    {
        return [
            'external_shipment_id' => 'carrier-100',
            'tracking_number' => 'TRACK-100',
            'label_url' => 'https://carrier.test/labels/100.pdf',
            'service_code' => 'express',
        ];
    }

    public function cancel(array $shipment): void
    {
    }

    public function track(array $shipment): array
    {
        return [[
            'external_event_id' => 'event-1',
            'status' => 'in_transit',
            'occurred_at' => '2026-07-19 14:00:00',
            'description' => 'Picked up',
            'location' => 'Tehran',
            'raw_hash' => hash('sha256', 'event-1'),
        ]];
    }

    public function parseWebhook(string $rawBody): array
    {
        return [[
            'external_event_id' => 'event-2',
            'external_shipment_id' => 'carrier-100',
            'status' => 'delivered',
            'occurred_at' => '2026-07-20 14:00:00',
            'description' => 'Delivered',
            'location' => 'Karaj',
            'raw_hash' => hash('sha256', $rawBody),
        ]];
    }
}
