<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Application\TaxSecretVault;
use RuntimeException;

final class WpTaxSecretVault implements TaxSecretVault
{
    private const CIPHER = 'aes-256-gcm';

    public function sealArray(array $value): string
    {
        return $this->seal(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function openArray(string $ciphertext): array
    {
        $value = json_decode($this->open($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Decrypted tax credentials are invalid.');
        }

        return $value;
    }

    public function seal(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt tax secret.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public function open(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) < 29) {
            throw new RuntimeException('Tax secret ciphertext is invalid.');
        }
        $plain = openssl_decrypt(
            substr($decoded, 28),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16)
        );
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt tax secret.');
        }

        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', implode('|', [
            defined('AUTH_KEY') ? AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
            defined('NONCE_KEY') ? NONCE_KEY : '',
            home_url('/'),
            'rishe-tax',
        ]), true);
    }
}
