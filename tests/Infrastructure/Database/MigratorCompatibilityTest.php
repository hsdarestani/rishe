<?php

declare(strict_types=1);

namespace Rishe\Tests\Infrastructure\Database;

use PHPUnit\Framework\TestCase;
use Rishe\Infrastructure\Database\Migrator;

final class MigratorCompatibilityTest extends TestCase
{
    public function testDbDeltaCreateQueriesUseDynamicInnoDbRows(): void
    {
        $migrator = new Migrator();

        $queries = $migrator->useCompatibleInnoDbTableOptions([
            'wp_rishe_audit_log' => 'CREATE TABLE wp_rishe_audit_log (id bigint NOT NULL);',
        ]);

        self::assertSame(
            'CREATE TABLE wp_rishe_audit_log (id bigint NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;',
            $queries['wp_rishe_audit_log']
        );
    }

    public function testExistingRowFormatIsNotRewritten(): void
    {
        $migrator = new Migrator();
        $query = 'CREATE TABLE wp_rishe_existing (id bigint NOT NULL) ENGINE=InnoDB ROW_FORMAT=COMPRESSED;';

        $queries = $migrator->useCompatibleInnoDbTableOptions(['wp_rishe_existing' => $query]);

        self::assertSame($query, $queries['wp_rishe_existing']);
    }
}
