<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

interface B2BRepository
{
    /** @return array<string, mixed>|null */
    public function customer(int $customerId): ?array;

    /** @return array<string, mixed>|null */
    public function warehouse(int $warehouseId): ?array;

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;

    /** @param array<string, mixed> $data @return array{id: int, created: bool} */
    public function upsertAccount(array $data): array;

    /** @return array<string, mixed>|null */
    public function account(int $accountId): ?array;

    /** @return array<string, mixed>|null */
    public function accountForUpdate(int $accountId): ?array;

    /** @return list<array<string, mixed>> */
    public function accounts(array $filters): array;

    public function nextDocumentNumber(string $type, int $fiscalYear): int;

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool, line_ids: list<int>} */
    public function createDispatch(array $data): array;

    public function attachDispatchTransfer(int $dispatchLineId, string $transferGroupId): void;

    public function finalizeDispatch(int $dispatchId): void;

    /** @return array<string, mixed>|null */
    public function dispatch(int $dispatchId): ?array;

    /** @return array<string, mixed>|null */
    public function dispatchForUpdate(int $dispatchId): ?array;

    /** @return list<array<string, mixed>> */
    public function dispatches(array $filters): array;

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool, line_ids: list<int>} */
    public function createReturn(array $data): array;

    public function attachReturnTransfer(int $returnLineId, string $transferGroupId): void;

    public function finalizeReturn(int $returnId, int $dispatchId, array $lineUpdates, string $dispatchStatus): void;

    /** @return array<string, mixed>|null */
    public function returnDocument(int $returnId): ?array;

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool, line_ids: list<int>} */
    public function createSalesReport(array $data): array;

    /** @return list<array{dispatch_line_id: int, quantity_scaled: int}> */
    public function allocateSoldQuantity(
        int $reportLineId,
        int $accountId,
        int $productId,
        int $quantityScaled
    ): array;

    public function attachSalesConsumption(
        int $reportLineId,
        int $reservationId,
        int $cogsIrr
    ): void;

    /** @param array{voucher_id: int, voucher_number: int}|null $accounting */
    public function finalizeSalesReport(
        int $reportId,
        int $accountId,
        int $receivableIrr,
        int $cogsIrr,
        string $dueDate,
        ?array $accounting
    ): void;

    /** @return array<string, mixed>|null */
    public function salesReport(int $reportId): ?array;

    /** @return list<array<string, mixed>> */
    public function salesReports(array $filters): array;

    /** @return array<string, mixed>|null */
    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array;

    /**
     * @param array{voucher_id: int, voucher_number: int}|null $accounting
     * @return array{id: int, idempotent: bool}
     */
    public function recordSettlement(
        int $accountId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array;

    /** @return list<array<string, mixed>> */
    public function statement(int $accountId): array;
}
