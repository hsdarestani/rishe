<?php

declare(strict_types=1);

namespace Rishe\Deployment\Infrastructure;

use Rishe\Deployment\Domain\BackupManifest;
use Rishe\Infrastructure\Database\Migrator;
use Rishe\Operations\Application\ConfigurationManager;
use Rishe\Shared\Audit\AuditRecorder;
use RuntimeException;
use ZipArchive;

final class WpBackupManager
{
    public function __construct(
        private ConfigurationManager $configuration,
        private BackupManifest $manifests,
        private AuditRecorder $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function create(?string $output = null): array
    {
        if (!class_exists('WP_CLI')) {
            throw new RuntimeException('Backup creation requires WP-CLI.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Backup creation requires the PHP zip extension.');
        }

        $uploads = wp_upload_dir();
        $baseDir = trailingslashit((string) $uploads['basedir']) . 'rishe-backups';
        if (!wp_mkdir_p($baseDir)) {
            throw new RuntimeException('Unable to create the Rishe backup directory.');
        }
        $stamp = gmdate('Ymd-His');
        $archive = $output !== null && trim($output) !== ''
            ? wp_normalize_path($output)
            : wp_normalize_path($baseDir . '/rishe-' . $stamp . '.zip');
        $workDir = wp_normalize_path($baseDir . '/.working-' . wp_generate_uuid4());
        if (!wp_mkdir_p($workDir)) {
            throw new RuntimeException('Unable to create a temporary backup directory.');
        }

        try {
            $databaseFile = $workDir . '/database.sql';
            $result = \WP_CLI::runcommand(
                'db export ' . escapeshellarg($databaseFile) . ' --add-drop-table',
                ['return' => 'all', 'exit_error' => false]
            );
            if ((int) ($result->return_code ?? 1) !== 0 || !is_readable($databaseFile)) {
                throw new RuntimeException('Database export failed: ' . (string) ($result->stderr ?? 'unknown error'));
            }

            $configuration = $this->configuration->export();
            $configurationFile = $workDir . '/configuration.json';
            $this->writeJson($configurationFile, $configuration);

            $files = [
                'database.sql' => hash_file('sha256', $databaseFile),
                'configuration.json' => hash_file('sha256', $configurationFile),
            ];
            $manifest = $this->manifests->build(
                $files,
                $this->tableRows(),
                home_url('/'),
                RISHE_VERSION,
                RISHE_DB_VERSION,
                gmdate('c')
            );
            $manifestFile = $workDir . '/manifest.json';
            $this->writeJson($manifestFile, $manifest);

            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create backup archive.');
            }
            foreach (['database.sql', 'configuration.json', 'manifest.json'] as $file) {
                $zip->addFile($workDir . '/' . $file, $file);
            }
            $zip->close();
            if (!is_readable($archive)) {
                throw new RuntimeException('Backup archive was not created.');
            }

            $result = [
                'archive' => $archive,
                'sha256' => hash_file('sha256', $archive),
                'size_bytes' => filesize($archive),
                'manifest_checksum' => $manifest['checksum'],
                'created_at' => $manifest['created_at'],
            ];
            $this->audit->record('deployment.backup.created', 'backup', (string) $manifest['checksum'], $result);

            return $result;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $archive): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Backup verification requires the PHP zip extension.');
        }
        $archive = wp_normalize_path($archive);
        if (!is_readable($archive)) {
            throw new RuntimeException('Backup archive is not readable.');
        }
        $uploads = wp_upload_dir();
        $workDir = trailingslashit((string) $uploads['basedir']) . 'rishe-backups/.verify-' . wp_generate_uuid4();
        if (!wp_mkdir_p($workDir)) {
            throw new RuntimeException('Unable to create backup verification directory.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true || !$zip->extractTo($workDir)) {
                throw new RuntimeException('Unable to extract backup archive.');
            }
            $zip->close();
            $manifest = $this->readJson($workDir . '/manifest.json');
            $this->manifests->validate($manifest);
            foreach ((array) ($manifest['files'] ?? []) as $file => $expectedHash) {
                $path = $workDir . '/' . basename((string) $file);
                if (!is_readable($path) || !hash_equals((string) $expectedHash, hash_file('sha256', $path))) {
                    throw new RuntimeException('Backup file checksum failed: ' . (string) $file);
                }
            }
            update_option('rishe_last_verified_backup_at', gmdate('c'), true);
            update_option('rishe_last_verified_backup_sha256', hash_file('sha256', $archive), true);
            $result = [
                'valid' => true,
                'archive' => $archive,
                'archive_sha256' => hash_file('sha256', $archive),
                'manifest' => $manifest,
            ];
            $this->audit->record(
                'deployment.backup.verified',
                'backup',
                (string) $manifest['checksum'],
                ['archive_sha256' => $result['archive_sha256']]
            );

            return $result;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /** @return array<string, mixed> */
    public function restore(string $archive, string $confirmation, bool $allowProduction, int $actorUserId): array
    {
        if (!class_exists('WP_CLI')) {
            throw new RuntimeException('Backup restore requires WP-CLI.');
        }
        if (!hash_equals(rtrim(home_url('/'), '/'), rtrim($confirmation, '/'))) {
            throw new RuntimeException('Restore confirmation must exactly match the current site URL.');
        }
        if (wp_get_environment_type() === 'production' && !$allowProduction) {
            throw new RuntimeException('Production restore requires --allow-production.');
        }

        $verified = $this->verify($archive);
        $safety = $this->create();
        $uploads = wp_upload_dir();
        $workDir = trailingslashit((string) $uploads['basedir']) . 'rishe-backups/.restore-' . wp_generate_uuid4();
        if (!wp_mkdir_p($workDir)) {
            throw new RuntimeException('Unable to create restore directory.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true || !$zip->extractTo($workDir)) {
                throw new RuntimeException('Unable to extract backup for restore.');
            }
            $zip->close();
            $import = \WP_CLI::runcommand(
                'db import ' . escapeshellarg($workDir . '/database.sql'),
                ['return' => 'all', 'exit_error' => false]
            );
            if ((int) ($import->return_code ?? 1) !== 0) {
                throw new RuntimeException('Database restore failed: ' . (string) ($import->stderr ?? 'unknown error'));
            }
            (new Migrator())->migrate();
            update_option('rishe_db_version', RISHE_DB_VERSION, true);

            $package = $this->readJson($workDir . '/configuration.json');
            $preview = $this->configuration->preview($package);
            $this->configuration->apply($package, (string) $preview['checksum'], max(1, $actorUserId));
            $this->audit->record('deployment.backup.restored', 'backup', (string) $verified['manifest']['checksum'], [
                'actor_user_id' => $actorUserId,
                'safety_backup' => $safety['archive'],
            ]);

            return [
                'restored' => true,
                'archive' => $archive,
                'safety_backup' => $safety['archive'],
                'database_version' => RISHE_DB_VERSION,
                'configuration_changes' => $preview['change_count'],
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /** @return array<string, int> */
    private function tableRows(): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($wpdb->prefix . 'rishe_') . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $rows = [];
        foreach ($tables as $table) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
            if ($safe === '') {
                continue;
            }
            $rows[$safe] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$safe}");
        }
        ksort($rows);

        return $rows;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($file, $encoded) === false) {
            throw new RuntimeException('Unable to write backup JSON file.');
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Backup JSON file is invalid.');
        }

        return $decoded;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
