<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface CarrierSecretVault
{
    /** @param array<string, mixed> $value */
    public function sealArray(array $value): string;

    /** @return array<string, mixed> */
    public function openArray(string $ciphertext): array;

    public function seal(string $value): string;

    public function open(string $ciphertext): string;
}
