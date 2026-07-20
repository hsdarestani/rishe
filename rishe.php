<?php

/**
 * Plugin Name: Rishe ERP
 * Plugin URI: https://github.com/hsdarestani/rishe
 * Description: Modular ERP and omnichannel operations platform for WooCommerce.
 * Version: 1.5.1
 * Author: Hossein Darestani
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: rishe
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RISHE_VERSION', '1.5.1');
define('RISHE_DB_VERSION', '2026071925');
define('RISHE_FILE', __FILE__);
define('RISHE_PATH', plugin_dir_path(__FILE__));
define('RISHE_URL', plugin_dir_url(__FILE__));

/**
 * Keep this guard free of PHP 8.1-only syntax. It must be able to explain the
 * problem instead of letting Composer or a typed class trigger a white screen.
 */
function rishe_environment_failure(string $message): void
{
    $safeMessage = esc_html($message);
    update_option('rishe_last_activation_error', [
        'message' => wp_strip_all_tags($message),
        'occurred_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'wordpress_version' => isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '',
    ], false);
    error_log('[Rishe ERP] ' . wp_strip_all_tags($message));

    add_action('admin_notices', static function () use ($safeMessage): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Rishe ERP:</strong> ' . $safeMessage . '</p></div>';
    });
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    rishe_environment_failure(
        sprintf('Rishe ERP requires PHP 8.1 or newer. The server is running PHP %s.', PHP_VERSION)
    );

    return;
}

$autoload = RISHE_PATH . 'vendor/autoload.php';
if (!is_readable($autoload)) {
    rishe_environment_failure(
        'Rishe ERP production dependencies are missing. Install the certified release ZIP instead of the repository source archive.'
    );

    return;
}

require_once $autoload;

register_activation_hook(RISHE_FILE, [\Rishe\Infrastructure\WordPress\Activator::class, 'activate']);
register_deactivation_hook(RISHE_FILE, [\Rishe\Infrastructure\WordPress\Activator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    try {
        (new \Rishe\Plugin())->boot();
        delete_option('rishe_last_runtime_error');
    } catch (\Throwable $exception) {
        $message = sprintf('%s: %s', $exception::class, $exception->getMessage());
        update_option('rishe_last_runtime_error', [
            'message' => $message,
            'occurred_at' => gmdate('c'),
            'plugin_version' => RISHE_VERSION,
            'database_version' => (string) get_option('rishe_db_version', ''),
        ], false);
        error_log('[Rishe ERP runtime] ' . $message);
        add_action('admin_notices', static function () use ($message): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p><strong>Rishe ERP runtime error:</strong> ';
                echo esc_html($message);
                echo '</p></div>';
            }
        });
    }
});
