<?php

/**
 * Plugin Name: ریشه – مدیریت یکپارچه کسب‌وکار
 * Plugin URI: https://github.com/hsdarestani/rishe
 * Description: سامانه یکپارچه فارسی برای حسابداری، انبار، تولید، فروش، خزانه‌داری، خرید، لجستیک و ووکامرس.
 * Version: 1.5.2
 * Author: Hossein Darestani
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: rishe
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RISHE_VERSION', '1.5.2');
define('RISHE_DB_VERSION', '2026071925');
define('RISHE_FILE', __FILE__);
define('RISHE_PATH', plugin_dir_path(__FILE__));
define('RISHE_URL', plugin_dir_url(__FILE__));

/**
 * This guard intentionally avoids PHP 8.1-only syntax so an old host receives
 * an actionable Persian message instead of a white screen.
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

        echo '<div class="notice notice-error"><p><strong>سامانه ریشه:</strong> ' . $safeMessage . '</p></div>';
    });
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    rishe_environment_failure(
        sprintf('سامانه ریشه به PHP نسخه ۸.۱ یا جدیدتر نیاز دارد. نسخه فعلی سرور: %s', PHP_VERSION)
    );

    return;
}

$autoload = RISHE_PATH . 'vendor/autoload.php';
if (!is_readable($autoload)) {
    rishe_environment_failure(
        'فایل‌های اجرایی سامانه ریشه کامل نیستند. بسته نصب رسمی افزونه را بارگذاری کنید و از فایل فشرده کد منبع استفاده نکنید.'
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
        $technicalMessage = sprintf('%s: %s', $exception::class, $exception->getMessage());
        update_option('rishe_last_runtime_error', [
            'message' => $technicalMessage,
            'occurred_at' => gmdate('c'),
            'plugin_version' => RISHE_VERSION,
            'database_version' => (string) get_option('rishe_db_version', ''),
        ], false);
        error_log('[Rishe ERP runtime] ' . $technicalMessage);
        add_action('admin_notices', static function (): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p><strong>خطای اجرای سامانه ریشه:</strong> ';
                echo esc_html__('اجرای افزونه با خطا روبه‌رو شد. جزئیات فنی در گزارش خطای سرور و بخش تنظیمات ریشه ثبت شده است.', 'rishe');
                echo '</p></div>';
            }
        });
    }
});
