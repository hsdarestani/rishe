<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury\Fakes;

use Rishe\Treasury\Application\PaymentLinkGateway;

final class InMemoryPaymentLinkGateway implements PaymentLinkGateway
{
    /** @var array<string, mixed> */
    public array $configured = [];

    /** @var array<string, mixed> */
    public array $callback = [
        'provider_link_id' => 'provider-link-1',
        'status' => 'paid',
        'amount_irr' => 250000,
        'external_transaction_id' => 'provider-tx-1',
        'paid_at' => '2026-07-19 18:00:00',
        'raw_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];

    public function configure(string $providerCode, array $secrets): void
    {
        $this->configured[$providerCode] = $secrets;
    }

    public function create(array $provider, array $link): array
    {
        return [
            'provider_link_id' => 'provider-link-1',
            'payment_url' => 'https://pay.example/link-1',
            'expires_at' => $link['expires_at'],
            'raw_hash' => null,
        ];
    }

    public function parseCallback(array $provider, string $body, array $headers): array
    {
        return $this->callback;
    }
}
