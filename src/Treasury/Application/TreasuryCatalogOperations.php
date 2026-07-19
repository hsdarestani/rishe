<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;

trait TreasuryCatalogOperations
{
    /** @param array<string, mixed> $data */
    public function createAccount(array $data, int $actorUserId): int
    {
        $type = strtolower(trim((string) ($data['type'] ?? '')));
        if (!in_array($type, self::ACCOUNT_TYPES, true)) {
            throw new TreasuryDomainException('Treasury account type is invalid.');
        }
        $payload = [
            'code' => $this->code($data['code'] ?? null),
            'name' => $this->name($data['name'] ?? null),
            'type' => $type,
            'bank_name' => $this->nullableText($data['bank_name'] ?? null, 100),
            'iban' => $this->nullableIdentifier($data['iban'] ?? null, 34),
            'account_number' => $this->nullableIdentifier($data['account_number'] ?? null, 50),
            'card_number' => $this->nullableIdentifier($data['card_number'] ?? null, 30),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'IRR'))),
            'subsidiary_ledger_id' => $this->optionalPositiveId($data['subsidiary_ledger_id'] ?? null),
            'floating_detail_id' => $this->optionalPositiveId($data['floating_detail_id'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];
        if ($payload['currency'] !== 'IRR') {
            throw new TreasuryDomainException('Treasury currently supports IRR accounts only.');
        }

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createAccount($payload);
            $this->audit->record('treasury.account.created', 'treasury_account', (string) $id, $payload);

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function createProvider(array $data, int $actorUserId): int
    {
        $adapter = strtolower(trim((string) ($data['adapter'] ?? 'configurable_hmac')));
        if (!in_array($adapter, ['configurable_hmac', 'blue_business'], true)) {
            throw new TreasuryDomainException('Payment provider adapter is invalid.');
        }
        $config = $data['config'] ?? [];
        if (!is_array($config)) {
            throw new TreasuryDomainException('Payment provider config must be an object.');
        }
        $secrets = $data['secrets'] ?? [];
        if (!is_array($secrets)) {
            throw new TreasuryDomainException('Payment provider secrets must be an object.');
        }
        $payload = [
            'code' => strtolower($this->code($data['code'] ?? null)),
            'name' => $this->name($data['name'] ?? null),
            'adapter' => $adapter,
            'treasury_account_id' => $this->positiveId($data['treasury_account_id'] ?? null, 'treasury_account_id'),
            'config' => $config,
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload, $secrets): int {
            $this->requireActiveAccount((int) $payload['treasury_account_id']);
            $id = $this->repository->createProvider($payload);
            if ($secrets !== []) {
                $this->gateway->configure((string) $payload['code'], $secrets);
            }
            $this->audit->record('treasury.provider.created', 'treasury_provider', (string) $id, [
                'code' => $payload['code'],
                'adapter' => $payload['adapter'],
                'treasury_account_id' => $payload['treasury_account_id'],
            ]);

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function createSettlement(array $data, int $actorUserId): int
    {
        $gross = $this->positiveMoney($data['gross_amount_irr'] ?? null, 'gross_amount_irr');
        $fee = $this->nonNegativeMoney($data['fee_amount_irr'] ?? 0, 'fee_amount_irr');
        $net = $this->positiveMoney($data['net_amount_irr'] ?? null, 'net_amount_irr');
        if ($gross - $fee !== $net) {
            throw new TreasuryDomainException('Settlement gross minus fee must equal net amount.');
        }
        $provider = $this->repository->providerByCode(strtolower($this->code($data['provider'] ?? null)));
        if ($provider === null) {
            throw new TreasuryDomainException('Settlement provider is missing.');
        }
        $payload = [
            'provider_id' => (int) $provider['id'],
            'treasury_account_id' => (int) $provider['treasury_account_id'],
            'external_settlement_id' => $this->requiredReference(
                $data['external_settlement_id'] ?? null,
                'external_settlement_id'
            ),
            'gross_amount_irr' => $gross,
            'fee_amount_irr' => $fee,
            'net_amount_irr' => $net,
            'settled_at' => $this->dateTime($data['settled_at'] ?? null, 'settled_at'),
            'raw_hash' => $this->nullableHash($data['raw_hash'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createSettlement($payload);
            $this->audit->record('treasury.settlement.created', 'treasury_settlement', (string) $id, [
                'gross_amount_irr' => $payload['gross_amount_irr'],
                'fee_amount_irr' => $payload['fee_amount_irr'],
                'net_amount_irr' => $payload['net_amount_irr'],
            ]);

            return $id;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function accounts(array $filters): array
    {
        return $this->repository->accounts($filters);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function providers(array $filters): array
    {
        return $this->repository->providers($filters);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function paymentLinks(array $filters): array
    {
        return $this->repository->paymentLinks($filters);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function transactions(array $filters): array
    {
        return $this->repository->transactions($filters);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function settlements(array $filters): array
    {
        return $this->repository->settlements($filters);
    }
}
