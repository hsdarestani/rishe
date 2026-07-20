<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database;

use Rishe\Infrastructure\Database\Migrations\CreateAccountingTables;
use Rishe\Infrastructure\Database\Migrations\CreateAnalyticsTables;
use Rishe\Infrastructure\Database\Migrations\CreateB2BTables;
use Rishe\Infrastructure\Database\Migrations\CreateFoundationTables;
use Rishe\Infrastructure\Database\Migrations\CreateInventoryTables;
use Rishe\Infrastructure\Database\Migrations\CreateLogisticsTables;
use Rishe\Infrastructure\Database\Migrations\CreateManufacturingTables;
use Rishe\Infrastructure\Database\Migrations\CreateOperationsTables;
use Rishe\Infrastructure\Database\Migrations\CreateProcurementTables;
use Rishe\Infrastructure\Database\Migrations\CreateSalesCrmTables;
use Rishe\Infrastructure\Database\Migrations\CreateTaxTables;
use Rishe\Infrastructure\Database\Migrations\CreateTreasuryTables;
use Rishe\Infrastructure\Database\Migrations\HardenB2BAccountGuard;
use Rishe\Infrastructure\Database\Migrations\ProtectAnalyticsLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectB2BLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectLogisticsLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectManufacturingLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectOperationsLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectPostedVouchers;
use Rishe\Infrastructure\Database\Migrations\ProtectProcurementLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectSalesLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectStockLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectTaxLedger;
use Rishe\Infrastructure\Database\Migrations\ProtectTreasuryLedger;
use Rishe\Infrastructure\Database\Migrations\ValidateJournalAssignments;
use RuntimeException;
use Throwable;

final class Migrator
{
    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
            new CreateFoundationTables(),
            new CreateAccountingTables(),
            new ProtectPostedVouchers(),
            new ValidateJournalAssignments(),
            new CreateInventoryTables(),
            new ProtectStockLedger(),
            new CreateManufacturingTables(),
            new ProtectManufacturingLedger(),
            new CreateSalesCrmTables(),
            new ProtectSalesLedger(),
            new CreateTreasuryTables(),
            new ProtectTreasuryLedger(),
            new CreateProcurementTables(),
            new ProtectProcurementLedger(),
            new CreateB2BTables(),
            new ProtectB2BLedger(),
            new HardenB2BAccountGuard(),
            new CreateLogisticsTables(),
            new ProtectLogisticsLedger(),
            new CreateTaxTables(),
            new ProtectTaxLedger(),
            new CreateOperationsTables(),
            new ProtectOperationsLedger(),
            new CreateAnalyticsTables(),
            new ProtectAnalyticsLedger(),
        ];
    }

    public function maybeMigrate(): void
    {
        if ((string) get_option('rishe_db_version', '') === RISHE_DB_VERSION) {
            return;
        }
        $this->migrate();
        update_option('rishe_db_version', RISHE_DB_VERSION, true);
    }

    public function migrate(): void
    {
        add_filter('dbdelta_create_queries', [$this, 'useCompatibleInnoDbTableOptions']);

        try {
            $this->ensureMigrationTable();
            foreach ($this->migrations() as $migration) {
                if ($this->hasRun($migration->id())) {
                    continue;
                }

                try {
                    $migration->up();
                } catch (Throwable $exception) {
                    throw $this->migrationFailure($migration->id(), $exception);
                }
                $this->record($migration->id());
            }
        } finally {
            remove_filter('dbdelta_create_queries', [$this, 'useCompatibleInnoDbTableOptions']);
        }
    }

    /**
     * Shared hosts sometimes retain the legacy COMPACT InnoDB default, whose
     * 767-byte key limit rejects valid utf8mb4 composite indexes. Make every
     * dbDelta-created Rishe table explicitly use the modern dynamic row format.
     *
     * @param array<string, string> $queries
     * @return array<string, string>
     */
    public function useCompatibleInnoDbTableOptions(array $queries): array
    {
        foreach ($queries as $table => $query) {
            if (stripos($query, 'ROW_FORMAT=') !== false) {
                continue;
            }

            $queries[$table] = rtrim(rtrim($query), ';') . ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC;';
        }

        return $queries;
    }

    private function ensureMigrationTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'rishe_migrations';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            migration varchar(191) NOT NULL,
            executed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY migration (migration)
        ) {$charset};");
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            throw $this->databaseFailure('Unable to create the Rishe migrations table.');
        }
    }

    private function hasRun(string $migration): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rishe_migrations';
        $query = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE migration = %s", $migration);

        return (int) $wpdb->get_var($query) > 0;
    }

    private function record(string $migration): void
    {
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'rishe_migrations',
            ['migration' => $migration, 'executed_at' => current_time('mysql', true)],
            ['%s', '%s']
        );
        if ($inserted === false) {
            throw $this->databaseFailure('Unable to record Rishe database migration.');
        }
    }

    private function migrationFailure(string $migration, Throwable $exception): RuntimeException
    {
        return $this->databaseFailure(
            sprintf('Migration %s failed: %s', $migration, $exception->getMessage()),
            $exception
        );
    }

    private function databaseFailure(string $message, ?Throwable $previous = null): RuntimeException
    {
        global $wpdb;

        $databaseError = trim((string) $wpdb->last_error);
        if ($databaseError !== '' && !str_contains($message, $databaseError)) {
            $message .= ' Database error: ' . $databaseError;
        }

        $server = method_exists($wpdb, 'db_server_info')
            ? trim((string) $wpdb->db_server_info())
            : trim((string) $wpdb->get_var('SELECT VERSION()'));
        if ($server !== '') {
            $message .= ' Database server: ' . $server . '.';
        }

        return new RuntimeException($message, 0, $previous);
    }
}
