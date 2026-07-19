<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface CarrierWebhookVerifier
{
    /** @param array<string, mixed> $carrier */
    public function verify(array $carrier, string $rawBody, string $signature): bool;
}
