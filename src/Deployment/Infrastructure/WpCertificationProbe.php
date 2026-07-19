<?php

declare(strict_types=1);

namespace Rishe\Deployment\Infrastructure;

use Rishe\Deployment\Application\CertificationProbe;
use Rishe\Operations\Application\DiagnosticsService;

final class WpCertificationProbe implements CertificationProbe
{
    public function __construct(private DiagnosticsService $diagnostics)
    {
    }

    public function checks(string $environment): array
    {
        global $wpdb;

        $checks = [];
        $diagnostics = $this->diagnostics->report();
        $diagnosticStatus = (string) ($diagnostics['status'] ?? 'critical');
        $checks[] = $this->check(
            'operations_diagnostics',
            match ($diagnosticStatus) {
                'ok' => 'pass',
                'warning' => 'warn',
                default => 'fail',
            },
            'Operations diagnostics status is ' . $diagnosticStatus . '.',
            ['counts' => $diagnostics['counts'] ?? []]
        );

        $databaseVersion = (string) $wpdb->get_var('SELECT VERSION()');
        $supportedDatabase = $this->supportedDatabase($databaseVersion);
        $checks[] = $this->check(
            'database_engine',
            $supportedDatabase ? 'pass' : 'fail',
            $supportedDatabase
                ? 'Database engine satisfies the supported production baseline.'
                : 'Database must be MySQL 8+ or MariaDB 10.6+.',
            ['version' => $databaseVersion]
        );

        $checks[] = $this->check(
            'database_migrations',
            (string) get_option('rishe_db_version', '') === RISHE_DB_VERSION ? 'pass' : 'fail',
            'Installed database version must match the release database version.',
            [
                'installed' => (string) get_option('rishe_db_version', ''),
                'expected' => RISHE_DB_VERSION,
            ]
        );

        $checks[] = $this->check(
            'https',
            is_ssl() ? 'pass' : ($environment === 'production' ? 'fail' : 'warn'),
            is_ssl() ? 'WordPress is served over HTTPS.' : 'HTTPS is required in production.'
        );

        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $checks[] = $this->check(
            'debug_mode',
            !$debug ? 'pass' : ($environment === 'production' ? 'fail' : 'warn'),
            $debug ? 'WP_DEBUG is enabled.' : 'WP_DEBUG is disabled.'
        );

        $checks[] = $this->check(
            'filesystem',
            wp_is_writable(WP_CONTENT_DIR) ? 'pass' : 'fail',
            'WordPress content directory must be writable for backup and deployment operations.',
            ['path' => WP_CONTENT_DIR]
        );

        $lastBackup = (string) get_option('rishe_last_verified_backup_at', '');
        $backupAge = $lastBackup === '' ? null : time() - (int) strtotime($lastBackup);
        $backupStatus = 'pass';
        if ($backupAge === null || $backupAge > 7 * DAY_IN_SECONDS) {
            $backupStatus = $environment === 'production' ? 'fail' : 'warn';
        } elseif ($backupAge > DAY_IN_SECONDS) {
            $backupStatus = 'warn';
        }
        $checks[] = $this->check(
            'verified_backup',
            $backupStatus,
            $lastBackup === '' ? 'No verified backup is recorded.' : 'Last verified backup: ' . $lastBackup,
            ['age_seconds' => $backupAge]
        );

        $checks[] = $this->providerCheck(
            'tax_profiles',
            $wpdb->prefix . 'rishe_tax_profiles',
            "is_active = 1 AND (gateway_config_json = '' OR credentials_ciphertext = '' OR private_key_ciphertext = '')",
            'Active taxpayer profiles must have gateway configuration, credentials, and private keys.'
        );
        $checks[] = $this->providerCheck(
            'logistics_carriers',
            $wpdb->prefix . 'rishe_logistics_carriers',
            "is_active = 1 AND (base_url = '' OR config_json = '' OR credentials_ciphertext = '')",
            'Active logistics carriers must have base URL, configuration, and credentials.'
        );
        $checks[] = $this->providerCheck(
            'treasury_providers',
            $wpdb->prefix . 'rishe_treasury_providers',
            "is_active = 1 AND config_json = ''",
            'Active treasury providers must have configuration.'
        );

        $failedJobs = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rishe_operation_jobs WHERE status = 'failed'"
        );
        $openIncidents = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rishe_system_incidents WHERE status <> 'resolved'"
        );
        $checks[] = $this->check(
            'operations_backlog',
            $failedJobs > 0 || $openIncidents > 0 ? 'warn' : 'pass',
            'Failed jobs and unresolved incidents should be cleared before promotion.',
            ['failed_jobs' => $failedJobs, 'open_incidents' => $openIncidents]
        );

        return $checks;
    }

    private function supportedDatabase(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return version_compare($version, '10.6', '>=');
        }

        return version_compare($version, '8.0', '>=');
    }

    /** @return array<string, mixed> */
    private function providerCheck(string $code, string $table, string $incompleteWhere, string $message): array
    {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        if (!$exists) {
            return $this->check($code, 'fail', 'Required provider table is missing.', ['table' => $table]);
        }
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
        $incomplete = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$incompleteWhere}");
        $status = $incomplete > 0 ? 'fail' : ($active > 0 ? 'pass' : 'warn');

        return $this->check($code, $status, $message, [
            'active' => $active,
            'incomplete' => $incomplete,
        ]);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function check(string $code, string $status, string $message, array $context = []): array
    {
        return compact('code', 'status', 'message', 'context');
    }
}
