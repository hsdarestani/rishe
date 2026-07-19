<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

interface TaxRepository
{
    /** @param array<string,mixed> $data @return array{id:int,created:bool} */
    public function upsertProfile(array $data): array;

    /** @return array<string,mixed>|null */
    public function profile(int $profileId): ?array;

    /** @return list<array<string,mixed>> */
    public function profiles(): array;

    /** @param array<string,mixed> $data @return array{id:int,created:bool} */
    public function upsertProductMapping(array $data): array;

    /** @return array<string,mixed>|null */
    public function productMapping(int $profileId, int $productId): ?array;

    /** @return list<array<string,mixed>> */
    public function productMappings(int $profileId): array;

    /** @return array<string,mixed>|null */
    public function salesOrder(int $salesOrderId): ?array;

    /** @param array<string,mixed> $data @return array{id:int,idempotent:bool} */
    public function createInvoice(array $data): array;

    /** @return array<string,mixed>|null */
    public function invoice(int $invoiceId): ?array;

    /** @return array<string,mixed>|null */
    public function invoiceForUpdate(int $invoiceId): ?array;

    /** @return list<array<string,mixed>> */
    public function invoices(array $filters): array;

    public function nextSerial(int $profileId, int $fiscalYear): int;

    /** @param array<string,mixed> $data */
    public function freezeInvoice(int $invoiceId, array $data): void;

    /** @return array{id:int,idempotent:bool} */
    public function createDerivedInvoice(int $sourceInvoiceId, string $subject, int $actorUserId): array;

    /** @param array<string,mixed> $data */
    public function recordSubmission(int $invoiceId, array $data): int;

    /** @param array<string,mixed> $data */
    public function recordStatus(int $invoiceId, array $data): int;

    public function updateInvoiceStatus(
        int $invoiceId,
        string $status,
        ?string $referenceNumber,
        ?string $uid,
        ?string $errorCode,
        ?string $errorMessage
    ): void;

    public function markSourceDerived(int $sourceInvoiceId, string $status, int $derivedInvoiceId): void;
}
