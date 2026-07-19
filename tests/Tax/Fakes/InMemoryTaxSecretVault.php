<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Tax\Application\TaxSecretVault;

final class InMemoryTaxSecretVault implements TaxSecretVault
{
    public function sealArray(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function openArray(string $ciphertext): array
    {
        return json_decode($ciphertext, true, 512, JSON_THROW_ON_ERROR);
    }

    public function seal(string $value): string
    {
        return base64_encode($value);
    }

    public function open(string $ciphertext): string
    {
        return base64_decode($ciphertext, true) ?: '';
    }
}
