<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\SystemProbe;

final class WpSystemProbe implements SystemProbe
{
    public function checks(): array
    {
        global $wpdb;

        $databaseReady = (int) $wpdb->get_var('SELECT 1') === 1;
        $checks = [
            $this->check('php_version', version_compare(PHP_VERSION, '8.1.0', '>='), 'critical', PHP_VERSION),
            $this->check('database_connection', $databaseReady, 'critical', $databaseReady ? 'connected' : 'failed'),
            $this->check(
                'database_version',
                (string) get_option('rishe_db_version', '') === RISHE_DB_VERSION,
                'critical',
                (string) get_option('rishe_db_version', 'not installed')
            ),
            $this->check('openssl', extension_loaded('openssl'), 'critical', extension_loaded('openssl') ? 'loaded' : 'missing'),
            $this->check('woocommerce', class_exists('WooCommerce'), 'warning', class_exists('WooCommerce') ? 'active' : 'inactive'),
            $this->check('https', is_ssl(), 'warning', is_ssl() ? 'enabled' : 'disabled'),
            $this->check(
                'scheduler',
                function_exists('as_schedule_single_action') || !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
                'critical',
                function_exists('as_schedule_single_action') ? 'action_scheduler' : 'wp_cron'
            ),
            $this->check(
                'wp_cron',
                !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
                'warning',
                defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'disabled' : 'enabled'
            ),
            $this->check('auth_salts', $this->saltsReady(), 'critical', $this->saltsReady() ? 'configured' : 'missing'),
        ];
        foreach ($this->requiredTables() as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
            $checks[] = $this->check('table.' . $suffix, $exists, 'critical', $exists ? 'present' : 'missing');
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    private function check(string $key, bool $passed, string $failureStatus, string $message): array
    {
        return [
            'key' => $key,
            'status' => $passed ? 'ok' : $failureStatus,
            'message' => $message,
        ];
    }

    private function saltsReady(): bool
    {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $constant) {
            if (!defined($constant) || trim((string) constant($constant)) === '') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function requiredTables(): array
    {
        return [
            'rishe_migrations',
            'rishe_audit_log',
            'rishe_outbox',
            'rishe_journal_vouchers',
            'rishe_inventory_batches',
            'rishe_sales_orders',
            'rishe_treasury_transactions',
            'rishe_purchase_orders',
            'rishe_b2b_accounts',
            'rishe_shipments',
            'rishe_tax_invoices',
            'rishe_operation_jobs',
            'rishe_operation_job_events',
            'rishe_system_incidents',
        ];
    }
}
