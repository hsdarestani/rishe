<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Sales\Domain\Exception\SalesDomainException;
use RuntimeException;

final class WpdbOrderMutationGateway
{
    /**
     * @param array<string, mixed> $data
     * @return array{id: int, order_key: string, line_ids: list<int>, idempotent: bool}
     */
    public function createOrder(array $data): array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $existing = null;
        if ($data['external_order_id'] !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$orders} WHERE channel = %s AND external_order_id = %s FOR UPDATE",
                $data['channel'],
                $data['external_order_id']
            ), ARRAY_A);
        } elseif ($data['idempotency_key'] !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$orders} WHERE idempotency_key = %s FOR UPDATE",
                $data['idempotency_key']
            ), ARRAY_A);
        }
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['source_hash'], (string) $data['source_hash'])) {
                throw new SalesDomainException('Order idempotency reference was reused with different commercial data.');
            }

            return [
                'id' => (int) $existing['id'],
                'order_key' => (string) $existing['order_key'],
                'line_ids' => $this->lineIds((int) $existing['id']),
                'idempotent' => true,
            ];
        }

        $now = current_time('mysql', true);
        $orderKey = wp_generate_uuid4();
        $totals = $data['totals'];
        $orderId = $this->insert('rishe_sales_orders', [
            'order_key' => $orderKey,
            'channel' => $data['channel'],
            'external_order_id' => $data['external_order_id'],
            'idempotency_key' => $data['idempotency_key'],
            'source_hash' => $data['source_hash'],
            'customer_id' => $data['customer_id'],
            'warehouse_id' => $data['warehouse_id'],
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'IRR',
            'gross_irr' => $totals['gross_irr'],
            'line_discount_irr' => $totals['line_discount_irr'],
            'subtotal_irr' => $totals['subtotal_irr'],
            'promotion_discount_irr' => $totals['promotion_discount_irr'],
            'loyalty_discount_irr' => $totals['loyalty_discount_irr'],
            'shipping_irr' => $totals['shipping_irr'],
            'tax_irr' => $totals['tax_irr'],
            'total_irr' => $totals['total_irr'],
            'cogs_irr' => null,
            'loyalty_points_redeemed' => $data['loyalty_points_redeemed'],
            'loyalty_points_earned' => 0,
            'promotion_id' => $data['promotion_id'],
            'accounting_status' => 'not_applicable',
            'accounting_voucher_id' => null,
            'accounting_voucher_number' => null,
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'paid_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s',
        ], 'sales order');

        $lineIds = [];
        foreach ($data['lines'] as $line) {
            $gross = intdiv((int) $line['quantity_scaled'] * (int) $line['unit_price_irr'], Quantity::SCALE);
            $net = $gross - (int) $line['line_discount_irr'];
            $lineIds[] = $this->insert('rishe_sales_order_lines', [
                'order_id' => $orderId,
                'product_id' => $line['product_id'],
                'sku_snapshot' => $line['sku_snapshot'],
                'name_snapshot' => $line['name_snapshot'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
                'gross_irr' => $gross,
                'line_discount_irr' => $line['line_discount_irr'],
                'net_irr' => $net,
                'reservation_id' => null,
                'cogs_irr' => null,
                'created_at' => $now,
            ], ['%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s'], 'order line');
        }

        if ($data['promotion_id'] !== null) {
            $this->insert('rishe_promotion_redemptions', [
                'promotion_id' => $data['promotion_id'],
                'customer_id' => $data['customer_id'],
                'order_id' => $orderId,
                'discount_irr' => $totals['promotion_discount_irr'],
                'created_at' => $now,
            ], ['%d', '%d', '%d', '%d', '%s'], 'promotion redemption');
        }
        if ((int) $data['loyalty_points_redeemed'] > 0) {
            $this->changeLoyalty(
                (int) $data['customer_id'],
                $orderId,
                'redeem',
                -(int) $data['loyalty_points_redeemed'],
                'Loyalty points redeemed for order ' . $orderId,
                (int) $data['actor_user_id']
            );
        }
        $this->statusHistory($orderId, null, 'pending_payment', (int) $data['actor_user_id'], 'Order created');

        return ['id' => $orderId, 'order_key' => $orderKey, 'line_ids' => $lineIds, 'idempotent' => false];
    }

    /** @return array<string, mixed>|null */
    public function orderForUpdate(int $orderId): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $customers = $wpdb->prefix . 'rishe_customers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, c.mobile_normalized, c.first_name, c.last_name, c.email
             FROM {$orders} o INNER JOIN {$customers} c ON c.id = o.customer_id
             WHERE o.id = %d FOR UPDATE",
            $orderId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row = $this->formatOrder($row);
        $row['lines'] = $this->lines($orderId, true);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function orderByKey(string $orderKey): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE order_key = %s",
            $orderKey
        ), ARRAY_A);

        return is_array($row) ? $this->formatOrder($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function paymentForUpdate(string $provider, string $externalPaymentId): ?array
    {
        global $wpdb;

        $payments = $wpdb->prefix . 'rishe_sales_payments';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$payments} WHERE provider = %s AND external_payment_id = %s FOR UPDATE",
            $provider,
            $externalPaymentId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'order_id', 'amount_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    public function attachReservation(int $lineId, int $reservationId): void
    {
        global $wpdb;

        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$lines} SET reservation_id = %d WHERE id = %d AND reservation_id IS NULL",
            $reservationId,
            $lineId
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to attach inventory reservation to sales order line.');
        }
    }

    public function markPaid(
        int $orderId,
        array $payment,
        array $lineCogs,
        ?array $accounting,
        int $loyaltyPointsEarned,
        int $actorUserId
    ): array {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE id = %d FOR UPDATE",
            $orderId
        ), ARRAY_A);
        if (!is_array($order) || (string) $order['status'] !== 'pending_payment') {
            throw new SalesDomainException('Sales order is no longer payable.');
        }

        $now = current_time('mysql', true);
        $paymentId = $this->insert('rishe_sales_payments', [
            'payment_key' => wp_generate_uuid4(),
            'order_id' => $orderId,
            'provider' => $payment['provider'],
            'external_payment_id' => $payment['external_payment_id'],
            'amount_irr' => $payment['amount_irr'],
            'status' => 'captured',
            'captured_at' => $now,
            'raw_hash' => $payment['raw_hash'],
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'], 'sales payment');

        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $totalCogs = 0;
        foreach ($lineCogs as $lineId => $cogs) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$lines} SET cogs_irr = %d
                 WHERE id = %d AND order_id = %d AND cogs_irr IS NULL",
                $cogs,
                $lineId,
                $orderId
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to store order line COGS.');
            }
            $totalCogs += $cogs;
        }

        if ($loyaltyPointsEarned > 0) {
            $this->changeLoyalty(
                (int) $order['customer_id'],
                $orderId,
                'earn',
                $loyaltyPointsEarned,
                'Loyalty points earned from order ' . $orderId,
                $actorUserId
            );
        }

        $accountingStatus = $accounting === null ? 'pending_configuration' : 'posted';
        $updated = $wpdb->update(
            $orders,
            [
                'status' => 'paid',
                'payment_status' => 'paid',
                'cogs_irr' => $totalCogs,
                'loyalty_points_earned' => $loyaltyPointsEarned,
                'accounting_status' => $accountingStatus,
                'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
                'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
                'paid_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $orderId, 'status' => 'pending_payment'],
            ['%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to mark sales order paid.');
        }
        $this->statusHistory($orderId, 'pending_payment', 'paid', $actorUserId, 'Payment captured');

        return ['payment_id' => $paymentId, 'loyalty_points_earned' => $loyaltyPointsEarned];
    }

    public function cancelOrder(int $orderId, int $actorUserId, string $reason): array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE id = %d FOR UPDATE",
            $orderId
        ), ARRAY_A);
        if (!is_array($order) || (string) $order['status'] !== 'pending_payment') {
            throw new SalesDomainException('Sales order is no longer cancellable without refund.');
        }

        $restored = (int) $order['loyalty_points_redeemed'];
        if ($restored > 0) {
            $this->changeLoyalty(
                (int) $order['customer_id'],
                $orderId,
                'release',
                $restored,
                'Loyalty redemption released for cancelled order ' . $orderId,
                $actorUserId
            );
        }
        $now = current_time('mysql', true);
        $updated = $wpdb->update(
            $orders,
            [
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'fulfillment_status' => 'cancelled',
                'cancelled_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $orderId, 'status' => 'pending_payment'],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to cancel sales order.');
        }
        $this->statusHistory($orderId, 'pending_payment', 'cancelled', $actorUserId, $reason);

        return ['loyalty_points_restored' => $restored];
    }

    public function completeOrder(int $orderId, int $actorUserId): void
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$orders} WHERE id = %d FOR UPDATE",
            $orderId
        ), ARRAY_A);
        if (!is_array($order) || !in_array((string) $order['status'], ['paid', 'fulfilling'], true)) {
            throw new SalesDomainException('Sales order cannot be completed from its current status.');
        }
        $from = (string) $order['status'];
        $now = current_time('mysql', true);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$orders} SET status = 'completed', fulfillment_status = 'fulfilled',
                completed_at = %s, updated_at = %s
             WHERE id = %d AND status IN ('paid', 'fulfilling')",
            $now,
            $now,
            $orderId
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to complete sales order.');
        }
        $this->statusHistory($orderId, $from, 'completed', $actorUserId, 'Order fulfilled');
    }

    public function setAccountingPosted(int $orderId, int $voucherId, int $voucherNumber): void
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$orders} SET accounting_status = 'posted', accounting_voucher_id = %d,
                accounting_voucher_number = %d, updated_at = %s
             WHERE id = %d AND accounting_status <> 'posted'",
            $voucherId,
            $voucherNumber,
            current_time('mysql', true),
            $orderId
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to link accounting voucher to sales order.');
        }
    }

    /** @return list<int> */
    private function lineIds(int $orderId): array
    {
        global $wpdb;

        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$lines} WHERE order_id = %d ORDER BY id",
            $orderId
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $orderId, bool $forUpdate): array
    {
        global $wpdb;

        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$lines} WHERE order_id = %d ORDER BY id{$suffix}",
            $orderId
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            $fields = [
                'id', 'order_id', 'product_id', 'quantity_scaled', 'unit_price_irr', 'gross_irr',
                'line_discount_irr', 'net_irr', 'reservation_id', 'cogs_irr',
            ];
            foreach ($fields as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }

            return $row;
        }, is_array($rows) ? $rows : []);
    }

    private function changeLoyalty(
        int $customerId,
        int $orderId,
        string $type,
        int $points,
        string $description,
        int $actorUserId
    ): void {
        global $wpdb;

        $customers = $wpdb->prefix . 'rishe_customers';
        $operator = $points >= 0 ? '+' : '-';
        $absolute = abs($points);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$customers} SET loyalty_balance = loyalty_balance {$operator} %d, updated_at = %s
             WHERE id = %d AND loyalty_balance {$operator} %d >= 0",
            $absolute,
            current_time('mysql', true),
            $customerId,
            $absolute
        ));
        if ($updated !== 1) {
            throw new SalesDomainException('Unable to change loyalty balance.');
        }
        $balance = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT loyalty_balance FROM {$customers} WHERE id = %d",
            $customerId
        ));
        $this->insert('rishe_loyalty_ledger', [
            'entry_key' => wp_generate_uuid4(),
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'entry_type' => $type,
            'points' => $points,
            'balance_after' => $balance,
            'description' => $description,
            'created_by' => $actorUserId,
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s'], 'loyalty ledger entry');
    }

    private function statusHistory(
        int $orderId,
        ?string $fromStatus,
        string $toStatus,
        int $actorUserId,
        string $reason
    ): void {
        $this->insert('rishe_order_status_history', [
            'order_id' => $orderId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%d', '%s', '%s'], 'order status history');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatOrder(array $row): array
    {
        $fields = [
            'id', 'customer_id', 'warehouse_id', 'gross_irr', 'line_discount_irr', 'subtotal_irr',
            'promotion_discount_irr', 'loyalty_discount_irr', 'shipping_irr', 'tax_irr', 'total_irr',
            'cogs_irr', 'loyalty_points_redeemed', 'loyalty_points_earned', 'promotion_id',
            'accounting_voucher_id', 'accounting_voucher_number', 'created_by',
        ];
        foreach ($fields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert(string $suffix, array $data, array $formats, string $entity): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . $suffix, $data, $formats);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create ' . $entity . ': ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }
}
