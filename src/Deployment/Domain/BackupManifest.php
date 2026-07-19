<?php

declare(strict_types=1);

namespace Rishe\Deployment\Domain;

use InvalidArgumentException;
use JsonException;

final class BackupManifest
{
    /**
     * @param array<string, string> $files
     * @param array<string, int> $tableRows
     * @return array<string, mixed>
     */
    public function build(
        array $files,
        array $tableRows,
        string $siteUrl,
        string $pluginVersion,
        string $databaseVersion,
        string $createdAt
    ): array {
        ksort($files);
        ksort($tableRows);
        $payload = [
            'schema' => 1,
            'site_url' => rtrim($siteUrl, '/'),
            'plugin_version' => $pluginVersion,
            'database_version' => $databaseVersion,
            'created_at' => $createdAt,
            'files' => $files,
            'table_rows' => $tableRows,
        ];
        $payload['checksum'] = $this->checksum($payload);

        return $payload;
    }

    /** @param array<string, mixed> $manifest */
    public function validate(array $manifest): void
    {
        $expected = strtolower(trim((string) ($manifest['checksum'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new InvalidArgumentException('Backup manifest checksum is missing or invalid.');
        }
        $payload = $manifest;
        unset($payload['checksum']);
        if (!hash_equals($expected, $this->checksum($payload))) {
            throw new InvalidArgumentException('Backup manifest checksum does not match its content.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        try {
            $json = json_encode(
                $this->normalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode backup manifest.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
