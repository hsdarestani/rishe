<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Application\CarrierGateway;
use Rishe\Logistics\Application\CarrierSecretVault;
use RuntimeException;

final class WpHttpJsonCarrierGateway implements CarrierGateway
{
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, mixed> */
    private array $credentials;

    public function __construct(
        private readonly array $carrier,
        CarrierSecretVault $vault
    ) {
        $config = json_decode((string) ($carrier['config_json'] ?? '{}'), true);
        $this->config = is_array($config) ? $config : [];
        $ciphertext = (string) ($carrier['credentials_ciphertext'] ?? '');
        $this->credentials = $ciphertext === '' ? [] : $vault->openArray($ciphertext);
    }

    public function quote(array $shipment): array
    {
        $response = $this->request('quote', $this->canonicalPayload($shipment));
        $mapped = $this->mapResponse('quote', $response);
        $mapped['amount_irr'] = $this->money($mapped['amount_irr'] ?? null);

        return $mapped;
    }

    public function book(array $shipment): array
    {
        return $this->mapResponse('book', $this->request('book', $this->canonicalPayload($shipment)));
    }

    public function cancel(array $shipment): void
    {
        $this->request('cancel', $this->canonicalPayload($shipment));
    }

    public function track(array $shipment): array
    {
        $response = $this->request('track', $this->canonicalPayload($shipment));
        $events = $this->valueAt($response, (string) ($this->config['tracking_events_path'] ?? 'events'));
        if (!is_array($events)) {
            throw new RuntimeException('Carrier tracking response does not contain an events array.');
        }

        return $this->mapEvents(array_values($events));
    }

    public function parseWebhook(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Carrier webhook body must decode to an object.');
        }
        $path = (string) ($this->config['webhook_events_path'] ?? 'events');
        $events = $this->valueAt($decoded, $path);
        if ($events === null && isset($decoded['status'])) {
            $events = [$decoded];
        }
        if (!is_array($events)) {
            throw new RuntimeException('Carrier webhook does not contain tracking events.');
        }

        return $this->mapEvents(array_values($events));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $operation, array $payload): array
    {
        $endpoints = $this->config['endpoints'] ?? [];
        if (!is_array($endpoints) || !isset($endpoints[$operation])) {
            throw new RuntimeException('Carrier endpoint is not configured for ' . $operation . '.');
        }
        $endpoint = $endpoints[$operation];
        $path = is_array($endpoint) ? (string) ($endpoint['path'] ?? '') : (string) $endpoint;
        $method = is_array($endpoint) ? strtoupper((string) ($endpoint['method'] ?? 'POST')) : 'POST';
        if ($path === '') {
            throw new RuntimeException('Carrier endpoint path is empty for ' . $operation . '.');
        }
        $requestPayload = $this->mapRequest($operation, $payload);
        $url = rtrim((string) $this->carrier['base_url'], '/') . '/' . ltrim($path, '/');
        if ($method === 'GET') {
            $url = add_query_arg($requestPayload, $url);
        }
        $args = [
            'method' => $method,
            'timeout' => (int) ($this->config['timeout_seconds'] ?? 20),
            'headers' => $this->headers(),
        ];
        if ($method !== 'GET') {
            $args['body'] = wp_json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException('Carrier request failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Carrier request failed with HTTP %d.', $status));
        }
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Carrier response must decode to an object.');
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $configured = $this->config['headers'] ?? [];
        if (is_array($configured)) {
            foreach ($configured as $name => $value) {
                $headers[(string) $name] = $this->interpolate((string) $value);
            }
        }
        $auth = $this->config['auth'] ?? [];
        if (is_array($auth) && isset($auth['header'], $auth['credential'])) {
            $credential = (string) ($this->credentials[(string) $auth['credential']] ?? '');
            if ($credential === '') {
                throw new RuntimeException('Configured carrier credential is missing.');
            }
            $scheme = trim((string) ($auth['scheme'] ?? ''));
            $headers[(string) $auth['header']] = $scheme === '' ? $credential : $scheme . ' ' . $credential;
        }

        return $headers;
    }

    /** @param array<string, mixed> $shipment @return array<string, mixed> */
    private function canonicalPayload(array $shipment): array
    {
        return [
            'shipment_id' => $shipment['public_id'] ?? $shipment['id'],
            'external_shipment_id' => $shipment['external_shipment_id'] ?? null,
            'tracking_number' => $shipment['tracking_number'] ?? null,
            'service_code' => $shipment['requested_service_code']
                ?? $shipment['selected_service_code']
                ?? null,
            'sender' => $shipment['sender'] ?? [],
            'recipient' => $shipment['recipient'] ?? [],
            'packages' => $shipment['packages'] ?? [],
            'declared_value_irr' => $shipment['declared_value_irr'] ?? 0,
            'cod_amount_irr' => $shipment['cod_amount_irr'] ?? 0,
            'reference' => $shipment['sales_order_id'] ?? $shipment['public_id'] ?? $shipment['id'],
        ];
    }

    /** @param array<string, mixed> $canonical @return array<string, mixed> */
    private function mapRequest(string $operation, array $canonical): array
    {
        $maps = $this->config['request_maps'] ?? [];
        $map = is_array($maps) && is_array($maps[$operation] ?? null) ? $maps[$operation] : [];
        if ($map === []) {
            return $canonical;
        }
        $result = [];
        foreach ($map as $target => $source) {
            $this->setAt($result, (string) $target, $this->valueAt($canonical, (string) $source));
        }

        return $result;
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function mapResponse(string $operation, array $response): array
    {
        $maps = $this->config['response_maps'] ?? [];
        $map = is_array($maps) && is_array($maps[$operation] ?? null) ? $maps[$operation] : [];
        if ($map === []) {
            return $response;
        }
        $result = [];
        foreach ($map as $target => $source) {
            $result[(string) $target] = $this->valueAt($response, (string) $source);
        }

        return $result;
    }

    /** @param list<mixed> $events @return list<array<string, mixed>> */
    private function mapEvents(array $events): array
    {
        $map = $this->config['event_map'] ?? [];
        $statusMap = $this->config['status_map'] ?? [];
        $mapped = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $row = [];
            foreach (['external_event_id', 'external_shipment_id', 'tracking_number', 'status', 'occurred_at', 'description', 'location'] as $field) {
                $path = is_array($map) ? (string) ($map[$field] ?? $field) : $field;
                $row[$field] = $this->valueAt($event, $path);
            }
            $providerStatus = strtolower(trim((string) ($row['status'] ?? '')));
            $row['status'] = is_array($statusMap) ? ($statusMap[$providerStatus] ?? $providerStatus) : $providerStatus;
            $row['raw_hash'] = hash(
                'sha256',
                json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            $mapped[] = $row;
        }

        return $mapped;
    }

    private function money(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('Carrier amount is not numeric.');
        }
        $multiplier = (int) ($this->config['amount_multiplier_to_irr'] ?? 1);
        if ($multiplier < 1) {
            throw new RuntimeException('Carrier amount multiplier must be positive.');
        }

        return (int) round((float) $value * $multiplier);
    }

    /** @param array<string, mixed> $source */
    private function valueAt(array $source, string $path): mixed
    {
        if ($path === '') {
            return null;
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

    /** @param array<string, mixed> $target */
    private function setAt(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$target;
        foreach ($segments as $index => $segment) {
            if ($index === array_key_last($segments)) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    private function interpolate(string $value): string
    {
        return preg_replace_callback('/\{credential:([a-zA-Z0-9_.-]+)\}/', function (array $match): string {
            return (string) ($this->credentials[$match[1]] ?? '');
        }, $value) ?? $value;
    }
}
