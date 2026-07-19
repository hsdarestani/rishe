<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface SystemProbe
{
    /** @return list<array<string, mixed>> */
    public function checks(): array;
}
