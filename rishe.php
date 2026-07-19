<?php

/**
 * Plugin Name: Rishe ERP
 * Plugin URI: https://github.com/hsdarestani/rishe
 * Description: Modular ERP and omnichannel operations platform for WooCommerce.
 * Version: 0.2.0
 * Author: Hossein Darestani
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: rishe
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RISHE_VERSION', '0.2.0');
define('RISHE_DB_VERSION', '2026071904');
define('RISHE_FILE', __FILE__);
define('RISHE_PATH', plugin_dir_path(__FILE__));
define('RISHE_URL', plugin_dir_url(__FILE__));

$autoload = RISHE_PATH . 'vendor/autoload.php';
if (!is_readable($autoload)) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Rishe ERP dependencies are missing. Run composer install in the plugin directory.', 'rishe');
        echo '</p></div>';
    });

    return;
}

require_once $autoload;

register_activation_hook(RISHE_FILE, [\Rishe\Infrastructure\WordPress\Activator::class, 'activate']);
register_deactivation_hook(RISHE_FILE, [\Rishe\Infrastructure\WordPress\Activator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    (new \Rishe\Plugin())->boot();
});
