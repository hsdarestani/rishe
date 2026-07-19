<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Application\CarrierGateway;
use Rishe\Logistics\Application\CarrierGatewayRegistry;
use Rishe\Logistics\Application\CarrierSecretVault;

final class WpCarrierGatewayRegistry implements CarrierGatewayRegistry
{
    public function __construct(private readonly CarrierSecretVault $vault)
    {
    }

    public function gateway(array $carrier): CarrierGateway
    {
        return new WpHttpJsonCarrierGateway($carrier, $this->vault);
    }
}
