<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\CarrierSecretVault;

final class InMemoryCarrierSecretVault implements CarrierSecretVault
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
        return 'sealed:' . $value;
    }

    public function open(string $ciphertext): string
    {
        return str_replace('sealed:', '', $ciphertext);
    }
}
