<?php

/**
 * Plugin Name: Rishe ERP
 * Plugin URI: https://github.com/hsdarestani/rishe
 * Description: Modular ERP and omnichannel operations platform for WooCommerce.
 * Version: 1.5.0
 * Author: Hossein Darestani
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: rishe
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RISHE_VERSION', '1.5.0');
define('RISHE_DB_VERSION', '2026071924');
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
    if (is_admin()) {
        add_action('admin_notices', static function () use ($safeMessage): void {
            echo '<div class="notice notice-error"><p><strong>Rishe ERP:</strong> ' . $safeMessage . '</p></div>';
        });
    }

    $activationAction = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
    if ($activationAction === 'activate' && function_exists('wp_die')) {
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(RISHE_FILE), true);
        }
        wp_die(
            '<h1>Rishe ERP فعال نشد</h1><p>' . $safeMessage . '</p>',
            'خطای پیش‌نیاز Rishe ERP',
            ['back_link' => true]
        );
    }
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    rishe_environment_failure(
        sprintf(
            'این افزونه به PHP 8.1 یا جدیدتر نیاز دارد. نسخه فعلی سرور %s است. نسخه PHP دامنه را روی 8.2 یا 8.3 قرار دهید.',
            PHP_VERSION
        )
    );

    return;
}

$autoload = RISHE_PATH . 'vendor/autoload.php';
if (!is_readable($autoload)) {
    rishe_environment_failure('فایل‌های وابستگی افزونه ناقص‌اند. باید ZIP رسمی Production نصب شود، نه Download ZIP مخزن.');

    return;
}

require_once $autoload;

register_activation_hook(RISHE_FILE, static function (): void {
    try {
        \Rishe\Infrastructure\WordPress\Activator::activate();
        delete_option('rishe_activation_error');
    } catch (\Throwable $exception) {
        $message = $exception->getMessage();
        update_option('rishe_activation_error', [
            'message' => $message,
            'exception' => get_class($exception),
            'php_version' => PHP_VERSION,
            'wordpress_version' => (string) ($GLOBALS['wp_version'] ?? ''),
            'created_at' => gmdate('c'),
        ], false);
        error_log('Rishe ERP activation failed: ' . $message);
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(plugin_basename(RISHE_FILE), true);
        }
        wp_die(
            '<h1>Rishe ERP فعال نشد</h1><p>' . esc_html($message) . '</p>'
            . '<p>نسخه PHP، نسخه دیتابیس و سطح دسترسی کاربر دیتابیس را بررسی کنید.</p>',
            'خطای فعال‌سازی Rishe ERP',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(RISHE_FILE, [\Rishe\Infrastructure\WordPress\Activator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    try {
        (new \Rishe\Plugin())->boot();
    } catch (\Throwable $exception) {
        update_option('rishe_runtime_error', [
            'message' => $exception->getMessage(),
            'exception' => get_class($exception),
            'created_at' => gmdate('c'),
        ], false);
        error_log('Rishe ERP boot failed: ' . $exception->getMessage());
        add_action('admin_notices', static function () use ($exception): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>Rishe ERP:</strong> '
                . esc_html($exception->getMessage()) . '</p></div>';
        });
    }
});
