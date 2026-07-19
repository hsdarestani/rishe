<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface CarrierGateway
{
    /** @param array<string, mixed> $shipment @return array<string, mixed> */
    public function quote(array $shipment): array;

    /** @param array<string, mixed> $shipment @return array<string, mixed> */
    public function book(array $shipment): array;

    /** @param array<string, mixed> $shipment */
    public function cancel(array $shipment): void;

    /** @param array<string, mixed> $shipment @return list<array<string, mixed>> */
    public function track(array $shipment): array;

    /** @return list<array<string, mixed>> */
    public function parseWebhook(string $rawBody): array;
}
