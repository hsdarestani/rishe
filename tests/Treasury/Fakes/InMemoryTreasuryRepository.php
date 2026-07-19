<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury\Fakes;

use Rishe\Treasury\Application\TreasuryRepository;

final class InMemoryTreasuryRepository implements TreasuryRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $accounts = [1 => ['id' => 1, 'is_active' => true]];

    /** @var array<int, array<string, mixed>> */
    public array $providers = [
        1 => [
            'id' => 1,
            'code' => 'blue_business',
            'adapter' => 'blue_business',
            'treasury_account_id' => 1,
            'config' => [],
            'is_active' => true,
        ],
    ];

    /** @var array<int, array<string, mixed>> */
    public array $links = [];

    /** @var array<int, array<string, mixed>> */
    public array $transactions = [];

    /** @var list<array<string, mixed>> */
    public array $matches = [];

    public function createAccount(array $data): int
    {
        $id = count($this->accounts) + 1;
        $this->accounts[$id] = $data + ['id' => $id, 'is_active' => true];

        return $id;
    }

    public function account(int $accountId): ?array
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function createProvider(array $data): int
    {
        $id = count($this->providers) + 1;
        $this->providers[$id] = $data + ['id' => $id, 'is_active' => true];

        return $id;
    }

    public function providerByCode(string $code): ?array
    {
        foreach ($this->providers as $provider) {
            if ($provider['code'] === $code) {
                return $provider;
            }
        }

        return null;
    }

    public function providerById(int $providerId): ?array
    {
        return $this->providers[$providerId] ?? null;
    }

    public function createPaymentLink(array $data): array
    {
        foreach ($this->links as $link) {
            if ($link['idempotency_key'] === $data['idempotency_key']) {
                return ['id' => $link['id'], 'idempotent' => true];
            }
        }
        $id = count($this->links) + 1;
        $this->links[$id] = $data + [
            'id' => $id,
            'public_id' => 'link-public-' . $id,
            'status' => 'creating',
            'provider_link_id' => null,
            'payment_url' => null,
            'paid_transaction_id' => null,
        ];

        return ['id' => $id, 'idempotent' => false];
    }

    public function paymentLink(int $paymentLinkId): ?array
    {
        return $this->links[$paymentLinkId] ?? null;
    }

    public function paymentLinkForUpdate(int $paymentLinkId): ?array
    {
        return $this->paymentLink($paymentLinkId);
    }

    public function paymentLinkByProviderReference(string $providerCode, string $providerLinkId): ?array
    {
        foreach ($this->links as $link) {
            if ($link['provider_code'] === $providerCode && $link['provider_link_id'] === $providerLinkId) {
                return $link;
            }
        }

        return null;
    }

    public function activatePaymentLink(int $paymentLinkId, array $providerResult): void
    {
        $this->links[$paymentLinkId]['status'] = 'active';
        $this->links[$paymentLinkId]['provider_link_id'] = $providerResult['provider_link_id'];
        $this->links[$paymentLinkId]['payment_url'] = $providerResult['payment_url'];
    }

    public function transitionPaymentLink(int $paymentLinkId, string $status, ?int $transactionId = null): void
    {
        $this->links[$paymentLinkId]['status'] = $status;
        $this->links[$paymentLinkId]['paid_transaction_id'] = $transactionId;
    }

    public function importTransaction(array $data): array
    {
        foreach ($this->transactions as $transaction) {
            if (
                $transaction['treasury_account_id'] === $data['treasury_account_id']
                && $transaction['external_transaction_id'] === $data['external_transaction_id']
            ) {
                return ['id' => $transaction['id'], 'idempotent' => true];
            }
        }
        $id = count($this->transactions) + 1;
        $this->transactions[$id] = $data + ['id' => $id, 'public_id' => 'tx-public-' . $id];

        return ['id' => $id, 'idempotent' => false];
    }

    public function transactionForUpdate(int $transactionId): ?array
    {
        return $this->transactions[$transactionId] ?? null;
    }

    public function matchedAmountForUpdate(int $transactionId): int
    {
        return array_sum(array_map(
            static fn (array $match): int => $match['treasury_transaction_id'] === $transactionId
                ? (int) $match['amount_irr']
                : 0,
            $this->matches
        ));
    }

    public function createMatch(array $data): int
    {
        $id = count($this->matches) + 1;
        $this->matches[] = $data + ['id' => $id];

        return $id;
    }

    public function createSettlement(array $data): int
    {
        return 1;
    }

    public function accounts(array $filters): array
    {
        return array_values($this->accounts);
    }

    public function providers(array $filters): array
    {
        return array_values($this->providers);
    }

    public function paymentLinks(array $filters): array
    {
        return array_values($this->links);
    }

    public function transactions(array $filters): array
    {
        return array_values($this->transactions);
    }

    public function settlements(array $filters): array
    {
        return [];
    }
}
