<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\ConfigurationStore;
use RuntimeException;

final class WpConfigurationStore implements ConfigurationStore
{
    public function get(string $key): mixed
    {
        return get_option($key, null);
    }

    public function set(string $key, mixed $value): void
    {
        if (!update_option($key, $value, true)) {
            $current = get_option($key, null);
            if ($current !== $value) {
                throw new RuntimeException('Unable to update configuration option ' . $key . '.');
            }
        }
    }
}
