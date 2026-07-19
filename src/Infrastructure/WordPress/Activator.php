<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\Migrator;
use RuntimeException;

final class Activator
{
    public static function activate(): void
    {
        self::assertRequirements();
        Capabilities::grant();
        (new Migrator())->migrate();

        update_option('rishe_version', RISHE_VERSION, true);
        update_option('rishe_db_version', RISHE_DB_VERSION, true);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('rishe/hourly_maintenance');
    }

    private static function assertRequirements(): void
    {
        global $wp_version;

        if (version_compare(PHP_VERSION, '8.1', '<')) {
            throw new RuntimeException('Rishe ERP requires PHP 8.1 or newer.');
        }

        if (version_compare((string) $wp_version, '6.5', '<')) {
            throw new RuntimeException('Rishe ERP requires WordPress 6.5 or newer.');
        }
    }
}
