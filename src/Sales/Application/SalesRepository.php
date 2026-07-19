<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

interface SalesRepository
{
    /** @param array<string, mixed> $data @return array{id: int, created: bool, loyalty_balance: int} */
    public function upsertCustomer(array $data): array;

    /** @return array<string, mixed>|null */
    public function customer(int $customerId): ?array;

    /** @return array<string, mixed>|null */
    public function product(int $productId): ?array;

    /** @return array<string, mixed>|null */
    public function productByWooCommerceId(int $wooCommerceProductId): ?array;

    public function activeChannelPrice(int $productId, string $channel, string $at): ?int;

    /** @param array<string, mixed> $data */
    public function createChannelPrice(array $data): int;

    /** @param array<string, mixed> $data */
    public function createPromotion(array $data): int;

    /** @return array<string, mixed>|null */
    public function promotion(string $code, string $channel, int $customerId, string $at): ?array;

    /** @return array{irr_per_point: int, earn_every_irr: int} */
    public function loyaltyPolicy(): array;

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, order_key: string, line_ids: list<int>, idempotent: bool}
     */
    public function createOrder(array $data): array;

    /** @return array<string, mixed>|null */
    public function orderForUpdate(int $orderId): ?array;

    /** @return array<string, mixed>|null */
    public function orderByKey(string $orderKey): ?array;

    /** @return array<string, mixed>|null */
    public function paymentForUpdate(string $provider, string $externalPaymentId): ?array;

    public function attachReservation(int $lineId, int $reservationId): void;

    /**
     * @param array<string, mixed> $payment
     * @param array<int, int> $lineCogs
     * @param array<string, int>|null $accounting
     * @return array{payment_id: int, loyalty_points_earned: int}
     */
    public function markPaid(
        int $orderId,
        array $payment,
        array $lineCogs,
        ?array $accounting,
        int $loyaltyPointsEarned,
        int $actorUserId
    ): array;

    /** @return array{loyalty_points_restored: int} */
    public function cancelOrder(int $orderId, int $actorUserId, string $reason): array;

    public function completeOrder(int $orderId, int $actorUserId): void;

    public function setAccountingPosted(int $orderId, int $voucherId, int $voucherNumber): void;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function orders(array $filters): array;

    /** @return array<string, mixed>|null */
    public function order(int $orderId): ?array;
}
