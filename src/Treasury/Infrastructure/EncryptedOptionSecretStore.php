<?php

declare(strict_types=1);

namespace Rishe\Treasury\Infrastructure;

use RuntimeException;

final class EncryptedOptionSecretStore
{
    /** @param array<string, mixed> $secrets */
    public function put(string $providerCode, array $secrets): void
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to store treasury provider secrets.');
        }
        $json = wp_json_encode($secrets);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode treasury provider secrets.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $json,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Unable to encrypt treasury provider secrets.');
        }
        update_option($this->optionName($providerCode), base64_encode($iv . $tag . $ciphertext), false);
    }

    /** @return array<string, mixed> */
    public function get(string $providerCode): array
    {
        $encoded = (string) get_option($this->optionName($providerCode), '');
        if ($encoded === '') {
            return [];
        }
        $payload = base64_decode($encoded, true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new RuntimeException('Stored treasury provider secrets are corrupted.');
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $json = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($json)) {
            throw new RuntimeException('Unable to decrypt treasury provider secrets.');
        }
        $secrets = json_decode($json, true);

        return is_array($secrets) ? $secrets : [];
    }

    private function key(): string
    {
        $material = function_exists('wp_salt')
            ? wp_salt('auth') . wp_salt('secure_auth')
            : (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        if ($material === '') {
            throw new RuntimeException('WordPress authentication salts are required for secret encryption.');
        }

        return hash('sha256', $material, true);
    }

    private function optionName(string $providerCode): string
    {
        return 'rishe_treasury_secret_' . hash('sha256', strtolower($providerCode));
    }
}
