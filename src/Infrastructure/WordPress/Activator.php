<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\Migrator;
use Rishe\Operations\Infrastructure\WordPress\OperationsRuntime;
use Rishe\Operations\Infrastructure\WpJobScheduler;
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
        wp_clear_scheduled_hook(WpJobScheduler::HOOK);
        wp_clear_scheduled_hook(OperationsRuntime::MAINTENANCE_HOOK);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(WpJobScheduler::HOOK, [], 'rishe-operations');
            as_unschedule_all_actions(OperationsRuntime::MAINTENANCE_HOOK, [], 'rishe-operations');
        }
    }

    private static function assertRequirements(): void
    {
        global $wp_version, $wpdb;

        if (version_compare(PHP_VERSION, '8.1', '<')) {
            throw new RuntimeException(
                sprintf('Rishe ERP requires PHP 8.1 or newer; the server is running PHP %s.', PHP_VERSION)
            );
        }

        if (version_compare((string) $wp_version, '6.5', '<')) {
            throw new RuntimeException(
                sprintf('Rishe ERP requires WordPress 6.5 or newer; the site is running WordPress %s.', $wp_version)
            );
        }

        $serverInfo = method_exists($wpdb, 'db_server_info')
            ? strtolower((string) $wpdb->db_server_info())
            : strtolower((string) $wpdb->get_var('SELECT VERSION()'));
        $version = preg_replace('/[^0-9.].*$/', '', (string) $wpdb->db_version()) ?: '0';
        $isMariaDb = str_contains($serverInfo, 'mariadb');
        $minimum = $isMariaDb ? '10.6' : '8.0';
        $engine = $isMariaDb ? 'MariaDB' : 'MySQL';
        if (version_compare($version, $minimum, '<')) {
            throw new RuntimeException(
                sprintf(
                    'Rishe ERP requires MySQL 8.0+ or MariaDB 10.6+. The server reports %s %s.',
                    $engine,
                    $version
                )
            );
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The PHP OpenSSL extension is required for encrypted credentials and tax signatures.');
        }

        $probe = $wpdb->get_var('SELECT 1');
        if ((string) $probe !== '1') {
            throw new RuntimeException('WordPress could not execute a database health query.');
        }
    }
}
