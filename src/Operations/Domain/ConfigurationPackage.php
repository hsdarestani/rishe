<?php

declare(strict_types=1);

namespace Rishe\Operations\Domain;

use Rishe\Operations\Domain\Exception\OperationsDomainException;

final class ConfigurationPackage
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function build(array $options, string $pluginVersion, string $generatedAt): array
    {
        $normalized = $this->normalize($options);
        $checksum = $this->checksum($normalized);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => $pluginVersion,
            'generated_at' => $generatedAt,
            'options' => $normalized,
            'checksum' => $checksum,
        ];
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    public function validate(array $package): array
    {
        if ((int) ($package['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new OperationsDomainException('Configuration package schema version is unsupported.');
        }
        if (!isset($package['options']) || !is_array($package['options'])) {
            throw new OperationsDomainException('Configuration package options are missing.');
        }
        $options = $this->normalize($package['options']);
        $checksum = strtolower(trim((string) ($package['checksum'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum) || !hash_equals($this->checksum($options), $checksum)) {
            throw new OperationsDomainException('Configuration package checksum is invalid.');
        }

        return $options;
    }

    /** @param array<string, mixed> $options */
    public function checksum(array $options): string
    {
        $normalized = $this->normalize($options);

        return hash(
            'sha256',
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArray($item);
            }
        }

        return $value;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function normalizeArray(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArray($item);
            }
        }

        return $value;
    }
}
