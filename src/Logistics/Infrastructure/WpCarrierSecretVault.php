<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Application\CarrierSecretVault;
use RuntimeException;

final class WpCarrierSecretVault implements CarrierSecretVault
{
    private const CIPHER = 'aes-256-gcm';

    public function sealArray(array $value): string
    {
        return $this->seal(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function openArray(string $ciphertext): array
    {
        $decoded = json_decode($this->open($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Decrypted carrier credentials are invalid.');
        }

        return $decoded;
    }

    public function seal(string $value): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to protect carrier credentials.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $value,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt carrier secret.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public function open(string $ciphertext): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required to read carrier credentials.');
        }
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) < 29) {
            throw new RuntimeException('Carrier secret ciphertext is invalid.');
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $payload = substr($decoded, 28);
        $plain = openssl_decrypt(
            $payload,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt carrier secret.');
        }

        return $plain;
    }

    private function key(): string
    {
        $material = implode('|', [
            defined('AUTH_KEY') ? AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
            defined('NONCE_KEY') ? NONCE_KEY : '',
            home_url('/'),
        ]);

        return hash('sha256', $material, true);
    }
}
