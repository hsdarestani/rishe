<?php

declare(strict_types=1);

namespace Rishe\Deployment\Application;

interface CertificationProbe
{
    /** @return list<array<string, mixed>> */
    public function checks(string $environment): array;
}
