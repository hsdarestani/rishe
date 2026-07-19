<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure;

use Rishe\Sales\Application\SalesRepository;
use RuntimeException;

final class WpdbSalesRepository implements SalesRepository
{
    private WpdbOrderMutationGateway $orders;

    public function __construct(?WpdbOrderMutationGateway $orders = null)
    {
        $this->orders = $orders ?? new WpdbOrderMutationGateway();
    }

    public function upsertCustomer(array $data): array
    {
        global $wpdb;

        $customers = $wpdb->prefix . 'rishe_customers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$customers} WHERE mobile_normalized = %s FOR UPDATE",
            $data['mobile_normalized']
        ), ARRAY_A);
        $created = false;
        $now = current_time('mysql', true);
        if (is_array($row)) {
            $updates = ['updated_at' => $now];
            $formats = ['%s'];
            foreach (['first_name', 'last_name', 'email'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    $updates[$field] = $data[$field];
                    $formats[] = '%s';
                }
            }
            if ($wpdb->update($customers, $updates, ['id' => $row['id']], $formats, ['%d']) === false) {
                throw new RuntimeException('Unable to update customer: ' . $wpdb->last_error);
            }
            $customerId = (int) $row['id'];
            $loyaltyBalance = (int) $row['loyalty_balance'];
        } else {
            $customerId = $this->insert('rishe_customers', [
                'customer_key' => wp_generate_uuid4(),
                'mobile_normalized' => $data['mobile_normalized'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'status' => 'active',
                'loyalty_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'], 'customer');
            $loyaltyBalance = 0;
            $created = true;
        }

        if (($data['channel'] ?? null) !== null && ($data['external_customer_id'] ?? null) !== null) {
            $channels = $wpdb->prefix . 'rishe_customer_channels';
            $metadata = wp_json_encode($data['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sql = $wpdb->prepare(
                "INSERT INTO {$channels}
                    (customer_id, channel, external_customer_id, metadata_json, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id),
                    metadata_json = VALUES(metadata_json), updated_at = VALUES(updated_at)",
                $customerId,
                $data['channel'],
                $data['external_customer_id'],
                $metadata === false ? '{}' : $metadata,
                $now,
                $now
            );
            if ($wpdb->query($sql) === false) {
                throw new RuntimeException('Unable to link customer channel: ' . $wpdb->last_error);
            }
        }

        return ['id' => $customerId, 'created' => $created, 'loyalty_balance' => $loyaltyBalance];
    }

    public function customer(int $customerId): ?array
    {
        global $wpdb;

        $customers = $wpdb->prefix . 'rishe_customers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$customers} WHERE id = %d",
            $customerId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        $channels = $wpdb->prefix . 'rishe_customer_channels';
        $ledger = $wpdb->prefix . 'rishe_loyalty_ledger';
        $row['id'] = (int) $row['id'];
        $row['loyalty_balance'] = (int) $row['loyalty_balance'];
        $row['channels'] = $wpdb->get_results($wpdb->prepare(
            "SELECT channel, external_customer_id, metadata_json, created_at, updated_at
             FROM {$channels} WHERE customer_id = %d ORDER BY id",
            $customerId
        ), ARRAY_A);
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$ledger} WHERE customer_id = %d ORDER BY id DESC LIMIT 100",
            $customerId
        ), ARRAY_A);
        $row['loyalty_ledger'] = array_map(static function (array $entry): array {
            foreach (['id', 'customer_id', 'order_id', 'points', 'balance_after', 'created_by'] as $field) {
                $entry[$field] = $entry[$field] === null ? null : (int) $entry[$field];
            }

            return $entry;
        }, is_array($entries) ? $entries : []);

        return $row;
    }

    public function product(int $productId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_products';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $productId
        ), ARRAY_A);

        return $this->formatProduct($row);
    }

    public function productByWooCommerceId(int $wooCommerceProductId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_products';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE wc_product_id = %d",
            $wooCommerceProductId
        ), ARRAY_A);

        return $this->formatProduct($row);
    }

    public function activeChannelPrice(int $productId, string $channel, string $at): ?int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_channel_prices';
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT unit_price_irr FROM {$table}
             WHERE product_id = %d AND channel = %s AND is_active = 1
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (ends_at IS NULL OR ends_at >= %s)
             ORDER BY starts_at DESC, id DESC LIMIT 1",
            $productId,
            $channel,
            $at,
            $at
        ));

        return $value === null ? null : (int) $value;
    }

    public function createChannelPrice(array $data): int
    {
        $this->assertActiveProduct((int) $data['product_id']);

        return $this->insert('rishe_channel_prices', [
            'product_id' => $data['product_id'],
            'channel' => $data['channel'],
            'unit_price_irr' => $data['unit_price_irr'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s'], 'channel price');
    }

    public function createPromotion(array $data): int
    {
        return $this->insert('rishe_promotions', [
            'promotion_key' => wp_generate_uuid4(),
            'code' => $data['code'],
            'name' => $data['name'],
            'discount_type' => $data['discount_type'],
            'value' => $data['value'],
            'max_discount_irr' => $data['max_discount_irr'],
            'min_order_irr' => $data['min_order_irr'],
            'channel' => $data['channel'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'usage_limit' => $data['usage_limit'],
            'per_customer_limit' => $data['per_customer_limit'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], [
            '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s',
        ], 'promotion');
    }

    public function promotion(string $code, string $channel, int $customerId, string $at): ?array
    {
        global $wpdb;

        $promotions = $wpdb->prefix . 'rishe_promotions';
        $redemptions = $wpdb->prefix . 'rishe_promotion_redemptions';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*,
                (SELECT COUNT(*) FROM {$redemptions} r WHERE r.promotion_id = p.id) AS usage_count,
                (SELECT COUNT(*) FROM {$redemptions} r
                    WHERE r.promotion_id = p.id AND r.customer_id = %d) AS customer_usage_count
             FROM {$promotions} p
             WHERE p.code = %s AND p.is_active = 1
               AND (p.channel IS NULL OR p.channel = %s)
               AND (p.starts_at IS NULL OR p.starts_at <= %s)
               AND (p.ends_at IS NULL OR p.ends_at >= %s)
             LIMIT 1 FOR UPDATE",
            $customerId,
            $code,
            $channel,
            $at,
            $at
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        if ($row['usage_limit'] !== null && (int) $row['usage_count'] >= (int) $row['usage_limit']) {
            return null;
        }
        if (
            $row['per_customer_limit'] !== null
            && (int) $row['customer_usage_count'] >= (int) $row['per_customer_limit']
        ) {
            return null;
        }
        foreach (['id', 'value', 'min_order_irr', 'usage_count', 'customer_usage_count'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['max_discount_irr', 'usage_limit', 'per_customer_limit'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    public function loyaltyPolicy(): array
    {
        $policy = get_option('rishe_loyalty_policy', []);
        $policy = is_array($policy) ? $policy : [];
        $irrPerPoint = max(0, (int) ($policy['irr_per_point'] ?? 1000));
        $earnEveryIrr = max(1, (int) ($policy['earn_every_irr'] ?? 100000));

        return ['irr_per_point' => $irrPerPoint, 'earn_every_irr' => $earnEveryIrr];
    }

    public function createOrder(array $data): array
    {
        return $this->orders->createOrder($data);
    }

    public function orderForUpdate(int $orderId): ?array
    {
        return $this->orders->orderForUpdate($orderId);
    }

    public function orderByKey(string $orderKey): ?array
    {
        return $this->orders->orderByKey($orderKey);
    }

    public function paymentForUpdate(string $provider, string $externalPaymentId): ?array
    {
        return $this->orders->paymentForUpdate($provider, $externalPaymentId);
    }

    public function attachReservation(int $lineId, int $reservationId): void
    {
        $this->orders->attachReservation($lineId, $reservationId);
    }

    public function markPaid(
        int $orderId,
        array $payment,
        array $lineCogs,
        ?array $accounting,
        int $loyaltyPointsEarned,
        int $actorUserId
    ): array {
        return $this->orders->markPaid(
            $orderId,
            $payment,
            $lineCogs,
            $accounting,
            $loyaltyPointsEarned,
            $actorUserId
        );
    }

    public function cancelOrder(int $orderId, int $actorUserId, string $reason): array
    {
        return $this->orders->cancelOrder($orderId, $actorUserId, $reason);
    }

    public function completeOrder(int $orderId, int $actorUserId): void
    {
        $this->orders->completeOrder($orderId, $actorUserId);
    }

    public function setAccountingPosted(int $orderId, int $voucherId, int $voucherNumber): void
    {
        $this->orders->setAccountingPosted($orderId, $voucherId, $voucherNumber);
    }

    public function orders(array $filters): array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $customers = $wpdb->prefix . 'rishe_customers';
        $clauses = ['1=1'];
        $args = [];
        foreach (['status', 'channel', 'customer_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $clauses[] = 'o.' . $field . ($field === 'customer_id' ? ' = %d' : ' = %s');
                $args[] = $filters[$field];
            }
        }
        if (($filters['from'] ?? null) !== null) {
            $clauses[] = 'o.created_at >= %s';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? null) !== null) {
            $clauses[] = 'o.created_at <= %s';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        $sql = "SELECT o.*, c.mobile_normalized, c.first_name, c.last_name
                FROM {$orders} o INNER JOIN {$customers} c ON c.id = o.customer_id
                WHERE " . implode(' AND ', $clauses) . ' ORDER BY o.id DESC LIMIT 200';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([$this, 'formatOrderRow'], is_array($rows) ? $rows : []);
    }

    public function order(int $orderId): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $customers = $wpdb->prefix . 'rishe_customers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, c.mobile_normalized, c.first_name, c.last_name, c.email
             FROM {$orders} o INNER JOIN {$customers} c ON c.id = o.customer_id
             WHERE o.id = %d",
            $orderId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row = $this->formatOrderRow($row);
        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $payments = $wpdb->prefix . 'rishe_sales_payments';
        $history = $wpdb->prefix . 'rishe_order_status_history';
        $row['lines'] = array_map([$this, 'formatLineRow'], $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$lines} WHERE order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A) ?: []);
        $row['payments'] = array_map([$this, 'formatPaymentRow'], $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$payments} WHERE order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A) ?: []);
        $row['status_history'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$history} WHERE order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A) ?: [];

        return $row;
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed>|null */
    private function formatProduct(?array $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'quantity_scale', 'wc_product_id', 'is_active'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatOrderRow(array $row): array
    {
        foreach ([
            'id', 'customer_id', 'warehouse_id', 'gross_irr', 'line_discount_irr', 'subtotal_irr',
            'promotion_discount_irr', 'loyalty_discount_irr', 'shipping_irr', 'tax_irr', 'total_irr',
            'cogs_irr', 'loyalty_points_redeemed', 'loyalty_points_earned', 'promotion_id',
            'accounting_voucher_id', 'accounting_voucher_number', 'created_by',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatLineRow(array $row): array
    {
        foreach ([
            'id', 'order_id', 'product_id', 'quantity_scaled', 'unit_price_irr', 'gross_irr',
            'line_discount_irr', 'net_irr', 'reservation_id', 'cogs_irr',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatPaymentRow(array $row): array
    {
        foreach (['id', 'order_id', 'amount_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    private function assertActiveProduct(int $productId): void
    {
        $product = $this->product($productId);
        if ($product === null || !(bool) $product['is_active']) {
            throw new RuntimeException('Product is missing or inactive.');
        }
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
