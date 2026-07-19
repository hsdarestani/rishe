<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Application\TaxGateway;
use Rishe\Tax\Application\TaxSecretVault;
use Rishe\Tax\Domain\Exception\TaxDomainException;
use RuntimeException;

final class WpHttpTaxGateway implements TaxGateway
{
    public function __construct(private readonly TaxSecretVault $vault)
    {
    }

    public function submit(array $profile, array $invoice): array
    {
        $config = $this->config($profile);
        $endpoint = $this->requiredEndpoint($config, 'submit_endpoint');
        $payload = [
            'fiscalId' => $profile['fiscal_memory_id'],
            'uid' => $invoice['public_id'],
            'taxId' => $invoice['tax_number'],
            'packet' => json_decode((string) $invoice['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            'signature' => $invoice['signature'],
        ];
        $root = trim((string) ($config['submit_root'] ?? ''));
        if ($root !== '') {
            $payload = [$root => [$payload]];
        }
        $response = $this->request($endpoint, (string) ($config['submit_method'] ?? 'POST'), $payload, $profile);

        return $this->normalizeResponse($response, $config);
    }

    public function inquire(array $profile, string $referenceNumber): array
    {
        $config = $this->config($profile);
        $endpoint = $this->requiredEndpoint($config, 'inquiry_endpoint');
        $payload = ['referenceNumbers' => [$referenceNumber]];
        $response = $this->request($endpoint, (string) ($config['inquiry_method'] ?? 'POST'), $payload, $profile);
        $itemsPath = (string) ($config['inquiry_items_path'] ?? 'result');
        $items = $this->path($response, $itemsPath);
        if (is_array($items) && array_is_list($items) && isset($items[0]) && is_array($items[0])) {
            $response = $items[0];
        }

        return $this->normalizeResponse($response, $config);
    }

    private function request(string $endpoint, string $method, array $payload, array $profile): array
    {
        $config = $this->config($profile);
        $credentials = $this->vault->openArray((string) $profile['credentials_ciphertext']);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach (($config['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        foreach (($config['credential_headers'] ?? []) as $name => $credentialKey) {
            if (array_key_exists((string) $credentialKey, $credentials)) {
                $headers[(string) $name] = (string) $credentials[(string) $credentialKey];
            }
        }
        $result = wp_remote_request($endpoint, [
            'method' => strtoupper($method),
            'timeout' => max(5, min(120, (int) ($config['timeout'] ?? 30))),
            'headers' => $headers,
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_format' => 'body',
        ]);
        if (is_wp_error($result)) {
            throw new RuntimeException('Tax gateway request failed: ' . $result->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($result);
        $decoded = json_decode((string) wp_remote_retrieve_body($result), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Tax gateway returned an invalid JSON response.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Tax gateway returned HTTP status ' . $status . '.');
        }

        return $decoded;
    }

    private function normalizeResponse(array $response, array $config): array
    {
        $itemsPath = (string) ($config['result_items_path'] ?? 'result');
        $item = $this->path($response, $itemsPath);
        if (is_array($item) && array_is_list($item) && isset($item[0]) && is_array($item[0])) {
            $item = $item[0];
        }
        if (!is_array($item)) {
            $item = $response;
        }

        return [
            'status' => (string) ($this->path($item, (string) ($config['status_path'] ?? 'status')) ?? 'submitted'),
            'reference_number' => $this->path($item, (string) ($config['reference_path'] ?? 'referenceNumber')),
            'uid' => $this->path($item, (string) ($config['uid_path'] ?? 'uid')),
            'error_code' => $this->path($item, (string) ($config['error_code_path'] ?? 'errorCode')),
            'error_message' => $this->path($item, (string) ($config['error_message_path'] ?? 'errorMessage')),
            'raw' => $response,
        ];
    }

    private function config(array $profile): array
    {
        $config = $profile['gateway_config'] ?? [];

        return is_array($config) ? $config : [];
    }

    private function requiredEndpoint(array $config, string $key): string
    {
        $endpoint = trim((string) ($config[$key] ?? ''));
        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new TaxDomainException('Tax gateway endpoint ' . $key . ' is missing or invalid.');
        }

        return $endpoint;
    }

    private function path(array $source, string $path): mixed
    {
        if ($path === '') {
            return $source;
        }
        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
