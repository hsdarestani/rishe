<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

interface PaymentLinkGateway
{
    /** @param array<string, mixed> $secrets */
    public function configure(string $providerCode, array $secrets): void;

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $link
     * @return array{provider_link_id: string, payment_url: string, expires_at: string|null, raw_hash: string|null}
     */
    public function create(array $provider, array $link): array;

    /**
     * @param array<string, mixed> $provider
     * @param array<string, string> $headers
     * @return array{provider_link_id: string, status: string, amount_irr: int, external_transaction_id: string, paid_at: string|null, raw_hash: string}
     */
    public function parseCallback(array $provider, string $body, array $headers): array;
}
