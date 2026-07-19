<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

interface TaxGatewayRegistry
{
    /** @param array<string,mixed> $profile */
    public function gateway(array $profile): TaxGateway;
}
