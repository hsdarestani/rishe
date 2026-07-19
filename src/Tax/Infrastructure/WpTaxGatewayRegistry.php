<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Application\TaxGateway;
use Rishe\Tax\Application\TaxGatewayRegistry;
use Rishe\Tax\Application\TaxSecretVault;
use Rishe\Tax\Domain\Exception\TaxDomainException;

final class WpTaxGatewayRegistry implements TaxGatewayRegistry
{
    public function __construct(private readonly TaxSecretVault $vault)
    {
    }

    public function gateway(array $profile): TaxGateway
    {
        if ((string) ($profile['gateway_type'] ?? '') !== 'http_json') {
            throw new TaxDomainException('Unsupported tax gateway type.');
        }

        return new WpHttpTaxGateway($this->vault);
    }
}
