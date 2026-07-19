<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use WP_REST_Request;
use WP_REST_Response;

final class RestApi
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('rishe/v1', '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health'],
            'permission_callback' => static fn(): bool => current_user_can('manage_rishe'),
        ]);
    }

    public function health(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        global $wpdb;
        $databaseReady = $wpdb->get_var('SELECT 1') === '1';

        return new WP_REST_Response([
            'status' => $databaseReady ? 'ok' : 'degraded',
            'plugin_version' => RISHE_VERSION,
            'database_version' => (string) get_option('rishe_db_version', ''),
            'woocommerce_active' => class_exists('WooCommerce'),
            'timestamp' => gmdate('c'),
        ], $databaseReady ? 200 : 503);
    }
}
