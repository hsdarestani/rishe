<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Application\TaxPayloadSigner;
use RuntimeException;

final class RsaTaxPayloadSigner implements TaxPayloadSigner
{
    public function sign(string $payload, string $privateKeyPem, ?string $keyId = null): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($keyId !== null && $keyId !== '') {
            $header['kid'] = $keyId;
        }
        $encodedHeader = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64Url($payload);
        $input = $encodedHeader . '.' . $encodedPayload;
        $signature = '';
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign tax payload with the configured RSA private key.');
        }

        return $input . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
