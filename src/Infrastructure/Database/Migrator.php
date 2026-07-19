<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database;

use Rishe\Infrastructure\Database\Migrations\CreateAccountingTables;
use Rishe\Infrastructure\Database\Migrations\CreateFoundationTables;
use Rishe\Infrastructure\Database\Migrations\ProtectPostedVouchers;
use Rishe\Infrastructure\Database\Migrations\ValidateJournalAssignments;
use RuntimeException;

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
        $this->ensureMigrationTable();

        foreach ($this->migrations() as $migration) {
            if ($this->hasRun($migration->id())) {
                continue;
            }

            $migration->up();
            $this->record($migration->id());
        }
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
            throw new RuntimeException('Unable to create the Rishe migrations table.');
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
            throw new RuntimeException('Unable to record Rishe database migration.');
        }
    }
}
