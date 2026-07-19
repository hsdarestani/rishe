<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

interface ProcurementRepository
{
    /** @param array<string, mixed> $data @return array{id: int, created: bool} */
    public function upsertSupplier(array $data): array;

    /** @return array<string, mixed>|null */
    public function supplier(int $supplierId): ?array;

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, idempotent: bool}
     */
    public function createPurchaseOrder(array $data): array;

    /** @return array<string, mixed>|null */
    public function purchaseOrderForUpdate(int $purchaseOrderId): ?array;

    /** @return array<string, mixed>|null */
    public function purchaseOrder(int $purchaseOrderId): ?array;

    public function nextDocumentNumber(string $type, int $fiscalYear): int;

    public function approvePurchaseOrder(
        int $purchaseOrderId,
        int $documentNumber,
        int $actorUserId,
        string $approvedAt
    ): void;

    public function cancelPurchaseOrder(int $purchaseOrderId, int $actorUserId, string $reason): void;

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, document_number: int, idempotent: bool, line_ids: list<int>}
     */
    public function createReceipt(array $data): array;

    public function attachInventoryBatch(int $receiptLineId, int $inventoryBatchId): void;

    /** @param array{voucher_id: int, voucher_number: int}|null $accounting */
    public function finalizeReceipt(
        int $receiptId,
        int $purchaseOrderId,
        string $purchaseOrderStatus,
        ?array $accounting
    ): void;

    /** @return array<string, mixed>|null */
    public function paymentByTreasuryTransaction(int $treasuryTransactionId): ?array;

    /**
     * @param array{voucher_id: int, voucher_number: int}|null $accounting
     * @return array{id: int, idempotent: bool}
     */
    public function recordPayment(
        int $purchaseOrderId,
        int $supplierId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array;

    /** @return list<array<string, mixed>> */
    public function suppliers(array $filters): array;

    /** @return list<array<string, mixed>> */
    public function purchaseOrders(array $filters): array;

    /** @return array<string, mixed>|null */
    public function receipt(int $receiptId): ?array;

    /** @return list<array<string, mixed>> */
    public function receipts(array $filters): array;

    /** @return list<array<string, mixed>> */
    public function supplierStatement(int $supplierId): array;
}
