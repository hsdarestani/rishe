<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\CarrierGateway;
use Rishe\Logistics\Application\CarrierGatewayRegistry;

final class InMemoryCarrierGatewayRegistry implements CarrierGatewayRegistry
{
    private InMemoryCarrierGateway $gateway;

    public function __construct()
    {
        $this->gateway = new InMemoryCarrierGateway();
    }

    public function gateway(array $carrier): CarrierGateway
    {
        return $this->gateway;
    }
}
