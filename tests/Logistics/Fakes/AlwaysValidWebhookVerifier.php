<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\CarrierWebhookVerifier;

final class AlwaysValidWebhookVerifier implements CarrierWebhookVerifier
{
    public function verify(array $carrier, string $rawBody, string $signature): bool
    {
        return $signature === 'valid';
    }
}
