<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

interface TaxPayloadSigner
{
    public function sign(string $payload, string $privateKeyPem, ?string $keyId = null): string;
}
