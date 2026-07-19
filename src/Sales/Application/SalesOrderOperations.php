<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

use Rishe\Sales\Domain\Exception\SalesDomainException;
use RuntimeException;

trait SalesOrderOperations
{
    public function createOrder(array $data, int $actorUserId): array
    {
        $channel = $this->channel($data['channel'] ?? 'manual');
        $warehouseId = $this->positiveId($data['warehouse_id'] ?? null, 'warehouse_id');
        $customerData = $data['customer'] ?? null;
        if (!is_array($customerData)) {
            throw new SalesDomainException('Order customer must be an object.');
        }
        $customerPayload = $this->customerPayload($customerData + ['channel' => $channel]);
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new SalesDomainException('An order must contain at least one line.');
        }

        $actor = $this->actor($actorUserId);
        $externalOrderId = $this->nullableText($data['external_order_id'] ?? null, 191);
        $idempotencyKey = $this->nullableText($data['idempotency_key'] ?? null, 100);
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);
        $shipping = $this->nonNegativeMoney($data['shipping_irr'] ?? 0, 'shipping_irr');
        $tax = $this->nonNegativeMoney($data['tax_irr'] ?? 0, 'tax_irr');
        $promotionCode = $this->nullableCode($data['promotion_code'] ?? null);
        $loyaltyPoints = $this->nonNegativeInteger($data['loyalty_points'] ?? 0, 'loyalty_points');
        $sourceHash = $this->nullableHash($data['source_hash'] ?? null);

        return $this->transactions->run(function () use (
            $channel,
            $warehouseId,
            $customerPayload,
            $rawLines,
            $actor,
            $externalOrderId,
            $idempotencyKey,
            $correlationId,
            $shipping,
            $tax,
            $promotionCode,
            $loyaltyPoints,
            $sourceHash
        ): array {
            $customer = $this->repository->upsertCustomer($customerPayload);
            $lines = $this->normalizeLines($rawLines, $channel);
            $promotion = $promotionCode === null
                ? null
                : $this->repository->promotion(
                    $promotionCode,
                    $channel,
                    (int) $customer['id'],
                    gmdate('Y-m-d H:i:s')
                );
            if ($promotionCode !== null && $promotion === null) {
                throw new SalesDomainException('Promotion is missing, inactive, expired, or exhausted.');
            }

            $policy = $this->repository->loyaltyPolicy();
            if ($loyaltyPoints > (int) $customer['loyalty_balance']) {
                throw new SalesDomainException('Customer loyalty balance is insufficient.');
            }
            $loyaltyDiscount = $loyaltyPoints * (int) $policy['irr_per_point'];
            $totals = $this->totals->calculate($lines, $promotion, $loyaltyDiscount, $shipping, $tax);
            if ($totals['total_irr'] < 1) {
                throw new SalesDomainException('Order total must be greater than zero.');
            }

            $commercialLines = array_map(static fn (array $line): array => [
                'product_id' => $line['product_id'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
                'line_discount_irr' => $line['line_discount_irr'],
            ], $lines);
            $commercialPayload = [
                'channel' => $channel,
                'external_order_id' => $externalOrderId,
                'customer_mobile' => $customerPayload['mobile_normalized'],
                'warehouse_id' => $warehouseId,
                'lines' => $commercialLines,
                'totals' => $totals,
                'promotion_id' => $promotion['id'] ?? null,
                'loyalty_points' => $loyaltyPoints,
            ];
            $orderSourceHash = $sourceHash ?? hash(
                'sha256',
                (string) json_encode($commercialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $result = $this->repository->createOrder([
                'channel' => $channel,
                'external_order_id' => $externalOrderId,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $orderSourceHash,
                'customer_id' => (int) $customer['id'],
                'warehouse_id' => $warehouseId,
                'lines' => $lines,
                'totals' => $totals,
                'promotion_id' => $promotion['id'] ?? null,
                'loyalty_points_redeemed' => $loyaltyPoints,
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
            ]);
            if ($result['idempotent']) {
                return $this->requireOrder((int) $result['id']);
            }

            foreach ($lines as $index => $line) {
                $lineId = (int) $result['line_ids'][$index];
                $reservationId = $this->inventory->reserve(
                    (int) $line['product_id'],
                    $warehouseId,
                    (int) $line['quantity_scaled'],
                    (string) $result['order_key'],
                    $lineId,
                    $actor,
                    $correlationId
                );
                $this->repository->attachReservation($lineId, $reservationId);
            }

            $this->audit->record(
                'sales.order.created',
                'sales_order',
                (string) $result['id'],
                [
                    'order_key' => $result['order_key'],
                    'channel' => $channel,
                    'customer_id' => (int) $customer['id'],
                    'total_irr' => $totals['total_irr'],
                ],
                $correlationId
            );

            return $this->requireOrder((int) $result['id']);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function capturePayment(int $orderId, array $data, int $actorUserId): array
    {
        $provider = $this->requiredIdentifier($data['provider'] ?? null, 'provider', 50);
        $externalPaymentId = $this->requiredReference($data['external_payment_id'] ?? null, 'external_payment_id');
        $amount = $this->nonNegativeMoney($data['amount_irr'] ?? null, 'amount_irr');
        $rawHash = $this->nullableHash($data['raw_hash'] ?? null);
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use (
            $orderId,
            $provider,
            $externalPaymentId,
            $amount,
            $rawHash,
            $actor
        ): array {
            $order = $this->repository->orderForUpdate($this->positiveId($orderId, 'order_id'));
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }

            $existingPayment = $this->repository->paymentForUpdate($provider, $externalPaymentId);
            if ($existingPayment !== null) {
                if (
                    (int) $existingPayment['order_id'] !== (int) $order['id']
                    || (int) $existingPayment['amount_irr'] !== $amount
                ) {
                    throw new SalesDomainException('Payment reference is already used for another payment.');
                }

                return $this->requireOrder((int) $order['id']) + ['idempotent' => true];
            }
            if ((string) $order['status'] !== 'pending_payment') {
                throw new SalesDomainException('Only a pending-payment order can be paid.');
            }
            if ($amount !== (int) $order['total_irr']) {
                throw new SalesDomainException('Captured payment amount must equal the order total.');
            }

            $lineCogs = [];
            $totalCogs = 0;
            foreach ($order['lines'] as $line) {
                $reservationId = (int) ($line['reservation_id'] ?? 0);
                if ($reservationId < 1) {
                    throw new RuntimeException('Order line has no inventory reservation.');
                }
                $committed = $this->inventory->commit($reservationId, $actor);
                $lineCogs[(int) $line['id']] = (int) $committed['cogs_irr'];
                $totalCogs += (int) $committed['cogs_irr'];
            }

            $postingOrder = $order;
            $postingOrder['cogs_irr'] = $totalCogs;
            $accounting = $this->accounting->postPaidOrder($postingOrder, $actor);
            $policy = $this->repository->loyaltyPolicy();
            $earned = (int) $policy['earn_every_irr'] > 0
                ? intdiv((int) $order['total_irr'], (int) $policy['earn_every_irr'])
                : 0;
            $paymentResult = $this->repository->markPaid(
                (int) $order['id'],
                [
                    'provider' => $provider,
                    'external_payment_id' => $externalPaymentId,
                    'amount_irr' => $amount,
                    'raw_hash' => $rawHash,
                ],
                $lineCogs,
                $accounting,
                $earned,
                $actor
            );

            $this->audit->record(
                'sales.order.paid',
                'sales_order',
                (string) $order['id'],
                [
                    'payment_id' => $paymentResult['payment_id'],
                    'provider' => $provider,
                    'amount_irr' => $amount,
                    'cogs_irr' => $totalCogs,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );

            return $this->requireOrder((int) $order['id']) + ['idempotent' => false];
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function capturePaymentByKey(string $orderKey, array $data, int $actorUserId): array
    {
        $order = $this->repository->orderByKey($this->requiredUuid($orderKey));
        if ($order === null) {
            throw new RuntimeException('Sales order not found.');
        }

        return $this->capturePayment((int) $order['id'], $data, $actorUserId);
    }

    public function cancelOrder(int $orderId, int $actorUserId, string $reason = ''): void
    {
        $actor = $this->actor($actorUserId);
        $this->transactions->run(function () use ($orderId, $actor, $reason): void {
            $order = $this->repository->orderForUpdate($this->positiveId($orderId, 'order_id'));
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }
            if ((string) $order['status'] !== 'pending_payment') {
                throw new SalesDomainException('Only a pending-payment order can be cancelled without a refund.');
            }

            foreach ($order['lines'] as $line) {
                $reservationId = (int) ($line['reservation_id'] ?? 0);
                if ($reservationId > 0) {
                    $this->inventory->release($reservationId, $actor);
                }
            }
            $result = $this->repository->cancelOrder((int) $order['id'], $actor, trim($reason));
            $this->audit->record(
                'sales.order.cancelled',
                'sales_order',
                (string) $order['id'],
                ['loyalty_points_restored' => $result['loyalty_points_restored'], 'reason' => trim($reason)],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );
        });
    }

    public function completeOrder(int $orderId, int $actorUserId): void
    {
        $actor = $this->actor($actorUserId);
        $this->transactions->run(function () use ($orderId, $actor): void {
            $order = $this->repository->orderForUpdate($this->positiveId($orderId, 'order_id'));
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }
            if (!in_array((string) $order['status'], ['paid', 'fulfilling'], true)) {
                throw new SalesDomainException('Only a paid or fulfilling order can be completed.');
            }
            $this->repository->completeOrder((int) $order['id'], $actor);
            $this->audit->record(
                'sales.order.completed',
                'sales_order',
                (string) $order['id'],
                [],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );
        });
    }

    /** @return array{voucher_id: int, voucher_number: int} */
    public function retryAccounting(int $orderId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($orderId, $actor): array {
            $order = $this->repository->orderForUpdate($this->positiveId($orderId, 'order_id'));
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }
            if (!in_array((string) $order['status'], ['paid', 'fulfilling', 'completed'], true)) {
                throw new SalesDomainException('Accounting can be posted only for a paid order.');
            }
            if ((string) $order['accounting_status'] === 'posted') {
                return [
                    'voucher_id' => (int) $order['accounting_voucher_id'],
                    'voucher_number' => (int) $order['accounting_voucher_number'],
                ];
            }

            $accounting = $this->accounting->postPaidOrder($order, $actor);
            if ($accounting === null) {
                throw new SalesDomainException('Sales accounting mapping is not configured.');
            }
            $this->repository->setAccountingPosted(
                (int) $order['id'],
                $accounting['voucher_id'],
                $accounting['voucher_number']
            );
            $this->audit->record(
                'sales.order.accounting_posted',
                'sales_order',
                (string) $order['id'],
                $accounting,
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );

            return $accounting;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function orders(array $filters): array
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['pending_payment', 'paid', 'fulfilling', 'completed', 'cancelled', 'refunded'], true)) {
            throw new SalesDomainException('Order status filter is invalid.');
        }
        $channel = trim((string) ($filters['channel'] ?? ''));

        return $this->repository->orders([
            'status' => $status === '' ? null : $status,
            'channel' => $channel === '' ? null : $this->channel($channel),
            'customer_id' => $this->optionalPositiveId($filters['customer_id'] ?? null),
            'from' => $this->nullableDate($filters['from'] ?? null),
            'to' => $this->nullableDate($filters['to'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    public function order(int $orderId): array
    {
        return $this->requireOrder($this->positiveId($orderId, 'order_id'));
    }
}
