<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations\Fakes;

use Rishe\Operations\Application\ConfigurationStore;

final class InMemoryConfigurationStore implements ConfigurationStore
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}
