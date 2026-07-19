<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Tax\Application\TaxPayloadSigner;

final class InMemoryTaxSigner implements TaxPayloadSigner
{
    public function sign(string $payload, string $privateKeyPem, ?string $keyId = null): string
    {
        return 'signature-' . hash('sha256', $payload . $privateKeyPem . $keyId);
    }
}
