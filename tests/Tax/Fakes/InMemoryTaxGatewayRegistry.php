<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Tax\Application\TaxGateway;
use Rishe\Tax\Application\TaxGatewayRegistry;

final class InMemoryTaxGatewayRegistry implements TaxGatewayRegistry
{
    public function __construct(public readonly InMemoryTaxGateway $gateway)
    {
    }

    public function gateway(array $profile): TaxGateway
    {
        return $this->gateway;
    }
}
