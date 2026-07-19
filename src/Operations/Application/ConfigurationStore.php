<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

interface ConfigurationStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;
}
