<?php

declare(strict_types=1);

namespace Rishe\Treasury\Infrastructure;

use Rishe\Treasury\Application\PaymentLinkGateway;
use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use RuntimeException;

final class WpPaymentLinkGateway implements PaymentLinkGateway
{
    public function __construct(private readonly EncryptedOptionSecretStore $secrets)
    {
    }

    public function configure(string $providerCode, array $secrets): void
    {
        $this->secrets->put($providerCode, $secrets);
    }

    public function create(array $provider, array $link): array
    {
        $config = $this->config($provider);
        $url = trim((string) ($config['create_url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TreasuryDomainException('Payment provider create_url is missing or invalid.');
        }
        $canonical = [
            'external_reference' => (string) ($link['public_id'] ?? $link['payment_link_id']),
            'amount_irr' => (int) $link['amount_irr'],
            'callback_url' => (string) $link['callback_url'],
            'expires_at' => $link['expires_at'],
            'description' => $link['description'],
            'metadata' => [
                'payment_link_id' => (int) $link['payment_link_id'],
                'sales_order_id' => $link['sales_order_id'],
                'customer_id' => $link['customer_id'],
                'reference_type' => $link['reference_type'],
                'reference_id' => $link['reference_id'],
            ],
        ];
        $payload = $this->mapPayload($canonical, $config['request_fields'] ?? []);
        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode payment-link request.');
        }
        $response = wp_remote_post($url, [
            'timeout' => max(5, min(60, (int) ($config['timeout'] ?? 20))),
            'headers' => $this->requestHeaders((string) $provider['code'], $config),
            'body' => $body,
            'data_format' => 'body',
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('Payment provider request failed: ' . $response->get_error_message());
        }
        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Payment provider rejected link creation with HTTP ' . $statusCode . '.');
        }
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Payment provider returned invalid JSON.');
        }
        $linkId = trim((string) $this->path(
            $decoded,
            (string) ($config['response_link_id_path'] ?? 'provider_link_id')
        ));
        $paymentUrl = trim((string) $this->path(
            $decoded,
            (string) ($config['response_url_path'] ?? 'payment_url')
        ));
        if ($linkId === '' || !filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Payment provider response is missing link id or payment URL.');
        }
        $expiresAt = $this->path($decoded, (string) ($config['response_expires_at_path'] ?? 'expires_at'));

        return [
            'provider_link_id' => $linkId,
            'payment_url' => $paymentUrl,
            'expires_at' => is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : $link['expires_at'],
            'raw_hash' => hash('sha256', $responseBody),
        ];
    }

    public function parseCallback(array $provider, string $body, array $headers): array
    {
        $config = $this->config($provider);
        $stored = $this->secrets->get((string) $provider['code']);
        $secret = (string) ($stored['webhook_secret'] ?? '');
        if ($secret === '') {
            throw new TreasuryDomainException('Payment provider webhook secret is not configured.');
        }
        $headerName = strtolower((string) ($config['webhook_signature_header'] ?? 'x-rishe-signature'));
        $signature = trim((string) ($headers[$headerName] ?? ''));
        if ($signature === '') {
            throw new TreasuryDomainException('Payment provider webhook signature is missing.');
        }
        $encoding = strtolower((string) ($config['webhook_signature_encoding'] ?? 'hex'));
        $expected = $encoding === 'base64'
            ? base64_encode(hash_hmac('sha256', $body, $secret, true))
            : hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new TreasuryDomainException('Payment provider webhook signature is invalid.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TreasuryDomainException('Payment provider callback must be valid JSON.');
        }
        $statusRaw = strtolower(trim((string) $this->path(
            $decoded,
            (string) ($config['callback_status_path'] ?? 'status')
        )));
        $statusMap = $config['callback_status_map'] ?? [];
        $status = is_array($statusMap) && isset($statusMap[$statusRaw])
            ? strtolower((string) $statusMap[$statusRaw])
            : $statusRaw;
        $providerLinkId = trim((string) $this->path(
            $decoded,
            (string) ($config['callback_link_id_path'] ?? 'provider_link_id')
        ));
        $externalTransactionId = trim((string) $this->path(
            $decoded,
            (string) ($config['callback_transaction_id_path'] ?? 'external_transaction_id')
        ));
        $amount = $this->path($decoded, (string) ($config['callback_amount_path'] ?? 'amount_irr'));
        $amountMultiplier = max(1, (int) ($config['amount_multiplier'] ?? 1));
        $amountIrr = filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($providerLinkId === '' || $externalTransactionId === '' || $amountIrr === false) {
            throw new TreasuryDomainException('Payment provider callback is missing required fields.');
        }
        $paidAt = $this->path($decoded, (string) ($config['callback_paid_at_path'] ?? 'paid_at'));

        return [
            'provider_link_id' => $providerLinkId,
            'status' => $status,
            'amount_irr' => (int) $amountIrr * $amountMultiplier,
            'external_transaction_id' => $externalTransactionId,
            'paid_at' => is_string($paidAt) && $paidAt !== '' ? $paidAt : null,
            'raw_hash' => hash('sha256', $body),
        ];
    }

    /** @param array<string, mixed> $provider @return array<string, mixed> */
    private function config(array $provider): array
    {
        $config = $provider['config'] ?? [];
        if (!is_array($config)) {
            throw new RuntimeException('Payment provider config is invalid.');
        }

        return $config;
    }

    /** @param array<string, mixed> $config @return array<string, string> */
    private function requestHeaders(string $providerCode, array $config): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $stored = $this->secrets->get($providerCode);
        $token = trim((string) ($stored['api_token'] ?? ''));
        if ($token !== '') {
            $header = (string) ($config['authorization_header'] ?? 'Authorization');
            $scheme = trim((string) ($config['authorization_scheme'] ?? 'Bearer'));
            $headers[$header] = $scheme === '' ? $token : $scheme . ' ' . $token;
        }
        $extra = $config['headers'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $headers[$key] = (string) $value;
                }
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param mixed $mapping
     * @return array<string, mixed>
     */
    private function mapPayload(array $canonical, mixed $mapping): array
    {
        if (!is_array($mapping) || $mapping === []) {
            return $canonical;
        }
        $payload = [];
        foreach ($mapping as $canonicalKey => $providerKey) {
            if (
                !is_string($canonicalKey)
                || !is_string($providerKey)
                || !array_key_exists($canonicalKey, $canonical)
            ) {
                continue;
            }
            $payload[$providerKey] = $canonical[$canonicalKey];
        }

        return $payload;
    }

    /** @param array<string, mixed> $data */
    private function path(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
