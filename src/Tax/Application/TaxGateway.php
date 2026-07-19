<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

interface TaxGateway
{
    /** @param array<string,mixed> $profile @param array<string,mixed> $invoice @return array<string,mixed> */
    public function submit(array $profile, array $invoice): array;

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function inquire(array $profile, string $referenceNumber): array;
}
