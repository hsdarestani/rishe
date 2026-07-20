<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestApi
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('rishe/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => static fn (): bool => current_user_can('manage_rishe'),
        ]);
        register_rest_route('rishe/v1', '/environment', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'environment'],
            'permission_callback' => static fn (): bool => current_user_can('manage_rishe'),
        ]);
    }

    public function health(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        global $wpdb;
        $databaseReady = (string) $wpdb->get_var('SELECT 1') === '1';

        return new WP_REST_Response([
            'status' => $databaseReady ? 'ok' : 'degraded',
            'plugin_version' => RISHE_VERSION,
            'database_version' => (string) get_option('rishe_db_version', ''),
            'woocommerce_active' => class_exists('WooCommerce'),
            'timestamp' => gmdate('c'),
        ], $databaseReady ? 200 : 503);
    }

    public function environment(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        global $wpdb, $wp_version;
        $serverInfo = method_exists($wpdb, 'db_server_info')
            ? (string) $wpdb->db_server_info()
            : (string) $wpdb->get_var('SELECT VERSION()');
        $isMariaDb = str_contains(strtolower($serverInfo), 'mariadb');
        $databaseVersion = (string) $wpdb->db_version();
        $databaseMinimum = $isMariaDb ? '10.6' : '8.0';
        $databaseSupported = version_compare($databaseVersion, $databaseMinimum, '>=');
        $uploadDirectory = wp_upload_dir();

        return new WP_REST_Response([
            'status' => version_compare(PHP_VERSION, '8.1', '>=')
                && version_compare((string) $wp_version, '6.5', '>=')
                && $databaseSupported
                ? 'ready'
                : 'unsupported',
            'plugin' => [
                'version' => RISHE_VERSION,
                'database_schema_version' => RISHE_DB_VERSION,
                'installed_database_version' => (string) get_option('rishe_db_version', ''),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'supported' => version_compare(PHP_VERSION, '8.1', '>='),
                'openssl' => extension_loaded('openssl'),
                'json' => extension_loaded('json'),
                'zip' => class_exists('ZipArchive'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'wordpress' => [
                'version' => (string) $wp_version,
                'supported' => version_compare((string) $wp_version, '6.5', '>='),
                'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'https' => is_ssl(),
                'uploads_writable' => empty($uploadDirectory['error'])
                    && is_dir((string) $uploadDirectory['basedir'])
                    && is_writable((string) $uploadDirectory['basedir']),
            ],
            'database' => [
                'engine' => $isMariaDb ? 'MariaDB' : 'MySQL',
                'version' => $databaseVersion,
                'server_info' => $serverInfo,
                'minimum' => $databaseMinimum,
                'supported' => $databaseSupported,
            ],
            'integrations' => [
                'woocommerce_active' => class_exists('WooCommerce'),
                'action_scheduler_active' => function_exists('as_schedule_single_action'),
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            ],
            'last_activation_error' => get_option('rishe_activation_error', null),
            'last_runtime_error' => get_option('rishe_runtime_error', null),
        ]);
    }
}
