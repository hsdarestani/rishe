<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface CarrierGatewayRegistry
{
    /** @param array<string, mixed> $carrier */
    public function gateway(array $carrier): CarrierGateway;
}
