<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

interface LogisticsRepository
{
    /** @param array<string, mixed> $data @return array{id: int, created: bool} */
    public function upsertCarrier(array $data): array;

    /** @return array<string, mixed>|null */
    public function carrier(int $carrierId): ?array;

    /** @return array<string, mixed>|null */
    public function carrierByCode(string $code): ?array;

    /** @return list<array<string, mixed>> */
    public function carriers(array $filters): array;

    /** @return array<string, mixed>|null */
    public function salesOrder(int $salesOrderId): ?array;

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool} */
    public function createShipment(array $data): array;

    /** @return array<string, mixed>|null */
    public function shipment(int $shipmentId): ?array;

    /** @return array<string, mixed>|null */
    public function shipmentForUpdate(int $shipmentId): ?array;

    /** @return array<string, mixed>|null */
    public function shipmentByCarrierReference(int $carrierId, string $reference): ?array;

    /** @return list<array<string, mixed>> */
    public function shipments(array $filters): array;

    /** @param array<string, mixed> $quote */
    public function recordQuote(int $shipmentId, array $quote): int;

    public function selectQuote(int $shipmentId, int $carrierId, int $quoteId, int $quotedCostIrr): void;

    /** @param array<string, mixed> $booking */
    public function recordBooking(int $shipmentId, int $carrierId, array $booking): void;

    public function updateShipmentStatus(int $shipmentId, string $status, ?string $occurredAt = null): void;

    /** @param array<string, mixed> $event @return array{id: int, idempotent: bool} */
    public function appendTrackingEvent(int $shipmentId, int $carrierId, array $event): array;

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool} */
    public function recordCost(int $shipmentId, array $data): array;

    /** @return array<string, mixed>|null */
    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array;

    /**
     * @param array{voucher_id: int, voucher_number: int}|null $accounting
     * @return array{id: int, idempotent: bool}
     */
    public function recordSettlement(
        int $shipmentId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array;
}
