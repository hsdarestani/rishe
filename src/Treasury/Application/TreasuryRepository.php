<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

interface TreasuryRepository
{
    /** @param array<string, mixed> $data */
    public function createAccount(array $data): int;

    /** @return array<string, mixed>|null */
    public function account(int $accountId): ?array;

    /** @param array<string, mixed> $data */
    public function createProvider(array $data): int;

    /** @return array<string, mixed>|null */
    public function providerByCode(string $code): ?array;

    /** @return array<string, mixed>|null */
    public function providerById(int $providerId): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, idempotent: bool}
     */
    public function createPaymentLink(array $data): array;

    /** @return array<string, mixed>|null */
    public function paymentLink(int $paymentLinkId): ?array;

    /** @return array<string, mixed>|null */
    public function paymentLinkForUpdate(int $paymentLinkId): ?array;

    /** @return array<string, mixed>|null */
    public function paymentLinkByProviderReference(string $providerCode, string $providerLinkId): ?array;

    /** @param array<string, mixed> $providerResult */
    public function activatePaymentLink(int $paymentLinkId, array $providerResult): void;

    public function transitionPaymentLink(int $paymentLinkId, string $status, ?int $transactionId = null): void;

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, idempotent: bool}
     */
    public function importTransaction(array $data): array;

    /** @return array<string, mixed>|null */
    public function transactionForUpdate(int $transactionId): ?array;

    public function matchedAmountForUpdate(int $transactionId): int;

    /** @param array<string, mixed> $data */
    public function createMatch(array $data): int;

    /** @param array<string, mixed> $data */
    public function createSettlement(array $data): int;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function accounts(array $filters): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function providers(array $filters): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function paymentLinks(array $filters): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function transactions(array $filters): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function settlements(array $filters): array;
}
