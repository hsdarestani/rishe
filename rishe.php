<?php
/**
 * Plugin Name: Rishe ERP
 * Plugin URI: https://github.com/hsdarestani/rishe
 * Description: Modular ERP and omnichannel operations platform for WooCommerce.
 * Version: 0.1.0
 * Author: Hossein Darestani
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: rishe
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RISHE_VERSION', '0.1.0');
define('RISHE_FILE', __FILE__);
define('RISHE_PATH', plugin_dir_path(__FILE__));
define('RISHE_URL', plugin_dir_url(__FILE__));

$autoload = RISHE_PATH . 'vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

register_activation_hook(__FILE__, static function (): void {
    if (class_exists(\Rishe\Infrastructure\WordPress\Activator::class)) {
        \Rishe\Infrastructure\WordPress\Activator::activate();
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists(\Rishe\Plugin::class)) {
        return;
    }

    (new \Rishe\Plugin())->boot();
});
