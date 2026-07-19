<?php

declare(strict_types=1);

namespace Rishe\Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rishe\Deployment\Domain\BackupManifest;

final class BackupManifestTest extends TestCase
{
    public function testManifestIsDeterministicAndValidates(): void
    {
        $builder = new BackupManifest();
        $first = $builder->build(
            ['configuration.json' => str_repeat('b', 64), 'database.sql' => str_repeat('a', 64)],
            ['wp_rishe_z' => 2, 'wp_rishe_a' => 1],
            'https://example.test/',
            '1.2.0',
            '2026071922',
            '2026-07-20T00:00:00Z'
        );
        $second = $builder->build(
            ['database.sql' => str_repeat('a', 64), 'configuration.json' => str_repeat('b', 64)],
            ['wp_rishe_a' => 1, 'wp_rishe_z' => 2],
            'https://example.test',
            '1.2.0',
            '2026071922',
            '2026-07-20T00:00:00Z'
        );

        self::assertSame($first['checksum'], $second['checksum']);
        $builder->validate($first);
    }

    public function testTamperedManifestIsRejected(): void
    {
        $builder = new BackupManifest();
        $manifest = $builder->build(
            ['database.sql' => str_repeat('a', 64)],
            ['wp_rishe_a' => 1],
            'https://example.test',
            '1.2.0',
            '2026071922',
            '2026-07-20T00:00:00Z'
        );
        $manifest['table_rows']['wp_rishe_a'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $builder->validate($manifest);
    }
}
