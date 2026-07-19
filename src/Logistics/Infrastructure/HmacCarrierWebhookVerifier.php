<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Application\CarrierSecretVault;
use Rishe\Logistics\Application\CarrierWebhookVerifier;

final class HmacCarrierWebhookVerifier implements CarrierWebhookVerifier
{
    public function __construct(private readonly CarrierSecretVault $vault)
    {
    }

    public function verify(array $carrier, string $rawBody, string $signature): bool
    {
        $ciphertext = (string) ($carrier['webhook_secret_ciphertext'] ?? '');
        if ($ciphertext === '' || trim($signature) === '') {
            return false;
        }
        $secret = $this->vault->open($ciphertext);
        $hex = hash_hmac('sha256', $rawBody, $secret);
        $base64 = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        $provided = trim($signature);
        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $provided = substr($provided, 7);
        }

        return hash_equals($hex, strtolower($provided)) || hash_equals($base64, $provided);
    }
}
