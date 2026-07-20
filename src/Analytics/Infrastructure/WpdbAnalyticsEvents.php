<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure;

use RuntimeException;

trait WpdbAnalyticsEvents
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function appendBusinessEvent(array $data): array
    {
        global $wpdb;
        $table = $this->table('business_events');
        $sourceAudit = $data['source_audit_event_id'] ?? null;
        $sequence = (int) ($data['event_sequence'] ?? 0);
        if ($sourceAudit !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_audit_event_id = %s AND event_sequence = %d",
                $sourceAudit,
                $sequence
            ), ARRAY_A);
            if (is_array($existing)) {
                return $this->normalizeRow($existing) + ['idempotent' => true];
            }
        }
        $eventKey = (string) ($data['event_key'] ?? $this->uuid());
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $inserted = $wpdb->insert($table, [
            'event_key' => $eventKey,
            'event_group_key' => $data['event_group_key'] ?? null,
            'source_audit_event_id' => $sourceAudit,
            'event_sequence' => $sequence,
            'event_type' => $data['event_type'],
            'occurred_at' => $data['occurred_at'],
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'sales_channel' => $data['sales_channel'] ?? null,
            'source_code' => $data['source_code'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'product_line' => $data['product_line'] ?? null,
            'quantity_scaled' => (int) ($data['quantity_scaled'] ?? 0),
            'revenue_irr' => (int) ($data['revenue_irr'] ?? 0),
            'cogs_irr' => (int) ($data['cogs_irr'] ?? 0),
            'gross_profit_irr' => (int) ($data['gross_profit_irr'] ?? 0),
            'discount_irr' => (int) ($data['discount_irr'] ?? 0),
            'order_count' => (int) ($data['order_count'] ?? 0),
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'aggregate_type' => $data['aggregate_type'] ?? null,
            'aggregate_id' => $data['aggregate_id'] ?? null,
            'correlation_id' => $data['correlation_id'] ?? null,
            'payload_json' => $this->encode($payload),
            'created_at' => $this->now(),
        ], [
            '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ]);
        if ($inserted === false && $sourceAudit !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_audit_event_id = %s AND event_sequence = %d",
                $sourceAudit,
                $sequence
            ), ARRAY_A);
            if (is_array($existing)) {
                return $this->normalizeRow($existing) + ['idempotent' => true];
            }
        }
        $this->assertInserted($inserted, 'Unable to append business event');
        $event = $this->row($table, (int) $wpdb->insert_id);
        $event['idempotent'] = false;

        return $event;
    }

    /** @param array<string, mixed> $auditEvent @return list<array<string, mixed>> */
    public function businessRowsFromAudit(array $auditEvent): array
    {
        $auditType = strtolower((string) ($auditEvent['event_type'] ?? ''));
        $payload = is_array($auditEvent['payload'] ?? null) ? $auditEvent['payload'] : [];
        $eventType = $this->canonicalEventType($auditType, $payload);
        if ($eventType === null) {
            return [];
        }
        $auditId = (string) ($auditEvent['event_id'] ?? '');
        $occurredAt = (string) ($auditEvent['occurred_at'] ?? $auditEvent['created_at'] ?? $this->now());
        $actor = $this->nullableInt($auditEvent['actor_user_id'] ?? null);
        $aggregateType = (string) ($auditEvent['aggregate_type'] ?? '');
        $aggregateId = (string) ($auditEvent['aggregate_id'] ?? '');
        $correlation = isset($auditEvent['correlation_id']) ? (string) $auditEvent['correlation_id'] : null;
        $orderId = $this->resolveOrderId($aggregateType, $aggregateId, $payload);
        if ($orderId !== null && in_array($eventType, ['order_created', 'order_paid', 'order_cancelled', 'order_returned', 'discount_applied', 'coupon_used'], true)) {
            return $this->orderEventRows($orderId, $eventType, $auditId, $occurredAt, $actor, $auditType, $payload, $correlation);
        }

        $productId = $this->nullableInt($payload['product_id'] ?? ($aggregateType === 'product' ? $aggregateId : null));
        $dimensions = $productId !== null ? $this->productDimensions($productId) : [];
        $revenue = max(0, (int) ($payload['revenue_irr'] ?? $payload['amount_irr'] ?? 0));
        $cogs = max(0, (int) ($payload['cogs_irr'] ?? 0));
        $row = [
            'event_key' => $this->uuid(),
            'event_group_key' => null,
            'source_audit_event_id' => $auditId !== '' ? $auditId : null,
            'event_sequence' => 0,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'actor_user_id' => $actor,
            'branch_id' => $this->nullableInt($payload['branch_id'] ?? null),
            'sales_channel' => isset($payload['channel']) ? (string) $payload['channel'] : null,
            'source_code' => isset($payload['source_code']) ? (string) $payload['source_code'] : null,
            'campaign_id' => $this->nullableInt($payload['campaign_id'] ?? null),
            'customer_id' => $this->nullableInt($payload['customer_id'] ?? null),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_line' => $dimensions['product_line'] ?? ($payload['product_line'] ?? null),
            'quantity_scaled' => (int) ($payload['quantity_scaled'] ?? 0),
            'revenue_irr' => $revenue,
            'cogs_irr' => $cogs,
            'gross_profit_irr' => $revenue - $cogs,
            'discount_irr' => max(0, (int) ($payload['discount_irr'] ?? 0)),
            'order_count' => 0,
            'province' => isset($payload['province']) ? (string) $payload['province'] : null,
            'city' => isset($payload['city']) ? (string) $payload['city'] : null,
            'aggregate_type' => $aggregateType !== '' ? $aggregateType : null,
            'aggregate_id' => $aggregateId !== '' ? $aggregateId : null,
            'correlation_id' => $correlation,
            'payload' => ['audit_event_type' => $auditType] + $payload,
        ];

        return [$row];
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function events(array $filters = []): array
    {
        global $wpdb;
        $table = $this->table('business_events');
        $where = ['1=1'];
        $args = [];
        foreach (['event_type', 'sales_channel', 'source_code', 'product_line', 'province', 'city'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "{$field} = %s";
                $args[] = (string) $filters[$field];
            }
        }
        foreach (['campaign_id', 'customer_id', 'order_id', 'product_id'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "{$field} = %d";
                $args[] = (int) $filters[$field];
            }
        }
        if (!empty($filters['from'])) {
            $where[] = 'occurred_at >= %s';
            $args[] = (string) $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'occurred_at <= %s';
            $args[] = (string) $filters['to'] . ' 23:59:59';
        }
        $limit = max(1, min(2000, (int) ($filters['limit'] ?? 200)));
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit}";
        $rows = $wpdb->get_results($this->prepare($sql, $args), ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @return list<array<string, mixed>> */
    public function eventsAfterCursor(int $limit): array
    {
        global $wpdb;
        $table = $this->table('business_events');
        $cursor = $this->projectionCursor();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $cursor,
            $limit
        ), ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $event */
    public function projectEvent(array $event): void
    {
        global $wpdb;
        $date = substr((string) $event['occurred_at'], 0, 10);
        $this->upsertTimeDimension($date);
        $this->upsertCustomerDimension($event);
        $this->upsertProductDimension($event);
        $this->upsertOrderDimension($event);
        $facts = $this->table('analytics_facts_daily');
        $dimensionHash = hash('sha256', implode('|', [
            $date,
            (string) ($event['branch_id'] ?? ''),
            (string) ($event['sales_channel'] ?? ''),
            (string) ($event['source_code'] ?? ''),
            (string) ($event['campaign_id'] ?? ''),
            (string) ($event['customer_id'] ?? ''),
            (string) ($event['order_id'] ?? ''),
            (string) ($event['product_id'] ?? ''),
            (string) ($event['product_line'] ?? ''),
            (string) ($event['province'] ?? ''),
            (string) ($event['city'] ?? ''),
            (string) $event['event_type'],
        ]));
        $sql = $wpdb->prepare(
            "INSERT INTO {$facts}
            (fact_date, dimension_hash, event_type, branch_id, sales_channel, source_code, campaign_id, customer_id,
             order_id, product_id, product_line, province, city, sales_qty_scaled, revenue_irr, cogs_irr,
             gross_profit_irr, discount_irr, orders_count, events_count, created_at, updated_at)
            VALUES (%s, %s, %s, %d, %s, %s, %d, %d, %d, %d, %s, %s, %s, %d, %d, %d, %d, %d, %d, 1, %s, %s)
            ON DUPLICATE KEY UPDATE
             sales_qty_scaled = sales_qty_scaled + VALUES(sales_qty_scaled),
             revenue_irr = revenue_irr + VALUES(revenue_irr),
             cogs_irr = cogs_irr + VALUES(cogs_irr),
             gross_profit_irr = gross_profit_irr + VALUES(gross_profit_irr),
             discount_irr = discount_irr + VALUES(discount_irr),
             orders_count = orders_count + VALUES(orders_count),
             events_count = events_count + 1,
             updated_at = VALUES(updated_at)",
            $date,
            $dimensionHash,
            $event['event_type'],
            $event['branch_id'],
            $event['sales_channel'],
            $event['source_code'],
            $event['campaign_id'],
            $event['customer_id'],
            $event['order_id'],
            $event['product_id'],
            $event['product_line'],
            $event['province'],
            $event['city'],
            $event['quantity_scaled'],
            $event['revenue_irr'],
            $event['cogs_irr'],
            $event['gross_profit_irr'],
            $event['discount_irr'],
            $event['order_count'],
            $this->now(),
            $this->now()
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to project analytics daily fact: ' . $wpdb->last_error);
        }
    }

    public function projectionCursor(): int
    {
        global $wpdb;
        $table = $this->table('analytics_projection_state');

        return (int) $wpdb->get_var("SELECT last_event_id FROM {$table} WHERE projection_name = 'daily_facts'");
    }

    public function advanceProjectionCursor(int $eventId): void
    {
        global $wpdb;
        $table = $this->table('analytics_projection_state');
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (projection_name, last_event_id, updated_at)
             VALUES ('daily_facts', %d, %s)
             ON DUPLICATE KEY UPDATE last_event_id = GREATEST(last_event_id, VALUES(last_event_id)), updated_at = VALUES(updated_at)",
            $eventId,
            $this->now()
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to advance analytics projection cursor.');
        }
    }

    /** @return array<string, int> */
    public function captureInventorySnapshot(string $date): array
    {
        global $wpdb;
        $batches = $this->table('inventory_batches');
        $products = $this->table('products');
        $snapshots = $this->table('inventory_daily_snapshots');
        $facts = $this->table('analytics_facts_daily');
        $rows = $wpdb->get_results(
            "SELECT b.product_id, b.warehouse_id, SUM(b.quantity_on_hand) AS inventory_scaled,
                    p.sku, p.name
             FROM {$batches} b JOIN {$products} p ON p.id = b.product_id
             WHERE b.status IN ('active', 'quarantined')
             GROUP BY b.product_id, b.warehouse_id, p.sku, p.name",
            ARRAY_A
        );
        $count = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $dims = $this->productDimensions((int) $row['product_id']);
            $sales = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(SUM(sales_qty_scaled),0) AS sales_qty_scaled,
                        COALESCE(SUM(revenue_irr),0) AS revenue_irr,
                        COALESCE(SUM(cogs_irr),0) AS cogs_irr,
                        COALESCE(SUM(gross_profit_irr),0) AS gross_profit_irr
                 FROM {$facts} WHERE fact_date = %s AND product_id = %d",
                $date,
                $row['product_id']
            ), ARRAY_A) ?: [];
            $sql = $wpdb->prepare(
                "INSERT INTO {$snapshots}
                (snapshot_date, product_id, warehouse_id, sku, product_line, inventory_scaled, sales_qty_scaled,
                 revenue_irr, cogs_irr, gross_profit_irr, created_at, updated_at)
                VALUES (%s, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE sku = VALUES(sku), product_line = VALUES(product_line),
                 inventory_scaled = VALUES(inventory_scaled), sales_qty_scaled = VALUES(sales_qty_scaled),
                 revenue_irr = VALUES(revenue_irr), cogs_irr = VALUES(cogs_irr),
                 gross_profit_irr = VALUES(gross_profit_irr), updated_at = VALUES(updated_at)",
                $date,
                $row['product_id'],
                $row['warehouse_id'],
                $row['sku'],
                $dims['product_line'] ?? null,
                $row['inventory_scaled'],
                $sales['sales_qty_scaled'] ?? 0,
                $sales['revenue_irr'] ?? 0,
                $sales['cogs_irr'] ?? 0,
                $sales['gross_profit_irr'] ?? 0,
                $this->now(),
                $this->now()
            );
            if ($wpdb->query($sql) === false) {
                throw new RuntimeException('Unable to capture inventory analytics snapshot.');
            }
            ++$count;
        }

        return ['snapshots' => $count];
    }

    /** @param array<string, mixed> $event */
    private function upsertCustomerDimension(array $event): void
    {
        global $wpdb;
        if (empty($event['customer_id'])) {
            return;
        }
        $customers = $this->table('customers');
        $dimension = $this->table('analytics_dim_customers');
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, created_at FROM {$customers} WHERE id = %d",
            $event['customer_id']
        ), ARRAY_A);
        if (!is_array($customer)) {
            return;
        }
        $purchaseDate = in_array($event['event_type'], ['order_paid', 'order_returned'], true)
            ? substr((string) $event['occurred_at'], 0, 10)
            : null;
        $sql = $wpdb->prepare(
            "INSERT INTO {$dimension}
            (customer_id, province, city, register_date, first_purchase, last_purchase, source_code, updated_at)
            VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
             province = COALESCE(VALUES(province), province), city = COALESCE(VALUES(city), city),
             first_purchase = CASE WHEN VALUES(first_purchase) IS NULL THEN first_purchase
                 WHEN first_purchase IS NULL THEN VALUES(first_purchase) ELSE LEAST(first_purchase, VALUES(first_purchase)) END,
             last_purchase = CASE WHEN VALUES(last_purchase) IS NULL THEN last_purchase
                 WHEN last_purchase IS NULL THEN VALUES(last_purchase) ELSE GREATEST(last_purchase, VALUES(last_purchase)) END,
             source_code = COALESCE(source_code, VALUES(source_code)), updated_at = VALUES(updated_at)",
            $event['customer_id'],
            $event['province'],
            $event['city'],
            substr((string) $customer['created_at'], 0, 10),
            $purchaseDate,
            $purchaseDate,
            $event['source_code'],
            $this->now()
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to project customer analytics dimension.');
        }
    }

    /** @param array<string, mixed> $event */
    private function upsertProductDimension(array $event): void
    {
        global $wpdb;
        if (empty($event['product_id'])) {
            return;
        }
        $products = $this->table('products');
        $dimension = $this->table('analytics_dim_products');
        $batches = $this->table('inventory_batches');
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sku, name FROM {$products} WHERE id = %d",
            $event['product_id']
        ), ARRAY_A);
        if (!is_array($product)) {
            return;
        }
        $dims = $this->productDimensions((int) $event['product_id']);
        $batchCode = $wpdb->get_var($wpdb->prepare(
            "SELECT batch_code FROM {$batches} WHERE product_id = %d ORDER BY received_at DESC, id DESC LIMIT 1",
            $event['product_id']
        ));
        $sql = $wpdb->prepare(
            "INSERT INTO {$dimension}
            (product_id, sku, product_name, product_line, category, supplier_id, latest_batch_code, updated_at)
            VALUES (%d, %s, %s, %s, %s, %d, %s, %s)
            ON DUPLICATE KEY UPDATE sku = VALUES(sku), product_name = VALUES(product_name),
             product_line = VALUES(product_line), category = VALUES(category), supplier_id = VALUES(supplier_id),
             latest_batch_code = VALUES(latest_batch_code), updated_at = VALUES(updated_at)",
            $event['product_id'],
            $product['sku'],
            $product['name'],
            $event['product_line'] ?? ($dims['product_line'] ?? null),
            $dims['category'] ?? null,
            $dims['supplier_id'] ?? null,
            $batchCode ?: null,
            $this->now()
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to project product analytics dimension.');
        }
    }

    /** @param array<string, mixed> $event */
    private function upsertOrderDimension(array $event): void
    {
        global $wpdb;
        if (empty($event['order_id'])) {
            return;
        }
        $orders = $this->table('sales_orders');
        $dimension = $this->table('analytics_dim_orders');
        $attribution = $this->table('order_attribution');
        $campaigns = $this->table('analytics_campaigns');
        $sources = $this->table('analytics_sources');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.id, o.channel, o.status, o.total_irr, o.cogs_irr,
                    (o.line_discount_irr + o.promotion_discount_irr + o.loyalty_discount_irr) AS discount_irr,
                    o.paid_at, a.branch_id, a.salesperson_user_id, a.campaign_id,
                    COALESCE(s.code, %s) AS source_code
             FROM {$orders} o
             LEFT JOIN {$attribution} a ON a.order_id = o.id
             LEFT JOIN {$campaigns} c ON c.id = a.campaign_id
             LEFT JOIN {$sources} s ON s.id = COALESCE(a.source_id, c.source_id)
             WHERE o.id = %d",
            $event['source_code'],
            $event['order_id']
        ), ARRAY_A);
        if (!is_array($row)) {
            return;
        }
        $sql = $wpdb->prepare(
            "INSERT INTO {$dimension}
            (order_id, sales_channel, source_code, campaign_id, branch_id, salesperson_user_id,
             discount_irr, total_irr, cogs_irr, status, paid_at, updated_at)
            VALUES (%d, %s, %s, %d, %d, %d, %d, %d, %d, %s, %s, %s)
            ON DUPLICATE KEY UPDATE sales_channel = VALUES(sales_channel), source_code = VALUES(source_code),
             campaign_id = VALUES(campaign_id), branch_id = VALUES(branch_id), salesperson_user_id = VALUES(salesperson_user_id),
             discount_irr = VALUES(discount_irr), total_irr = VALUES(total_irr), cogs_irr = VALUES(cogs_irr),
             status = VALUES(status), paid_at = VALUES(paid_at), updated_at = VALUES(updated_at)",
            $row['id'],
            $row['channel'],
            $row['source_code'],
            $row['campaign_id'],
            $row['branch_id'],
            $row['salesperson_user_id'],
            $row['discount_irr'],
            $row['total_irr'],
            $row['cogs_irr'],
            $row['status'],
            $row['paid_at'],
            $this->now()
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to project order analytics dimension.');
        }
    }

    private function upsertTimeDimension(string $date): void
    {
        global $wpdb;
        $time = $this->table('analytics_dim_time');
        $stamp = strtotime($date . ' 00:00:00 UTC');
        $month = (int) gmdate('n', $stamp);
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$time}
            (date_key, day_of_week, week_of_year, month_number, quarter_number, year_number, month_key)
            VALUES (%s, %d, %d, %d, %d, %d, %s)",
            $date,
            (int) gmdate('N', $stamp),
            (int) gmdate('W', $stamp),
            $month,
            intdiv($month - 1, 3) + 1,
            (int) gmdate('Y', $stamp),
            gmdate('Y-m', $stamp)
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('Unable to project time analytics dimension.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function canonicalEventType(string $auditType, array $payload): ?string
    {
        if (str_contains($auditType, 'customer') && preg_match('/created|registered/', $auditType)) {
            return 'customer_registered';
        }
        if (str_contains($auditType, 'order') && str_contains($auditType, 'created')) {
            return 'order_created';
        }
        if (str_contains($auditType, 'payment') && preg_match('/captured|paid|matched/', $auditType)) {
            return 'order_paid';
        }
        if (str_contains($auditType, 'order') && preg_match('/cancelled|canceled/', $auditType)) {
            return 'order_cancelled';
        }
        if (preg_match('/return|refund/', $auditType)) {
            return 'order_returned';
        }
        if (str_contains($auditType, 'shipment') && preg_match('/booked|created/', $auditType)) {
            return 'shipment_created';
        }
        if ((string) ($payload['status'] ?? '') === 'delivered' || str_contains($auditType, 'delivered')) {
            return 'order_delivered';
        }
        if (preg_match('/price/', $auditType)) {
            return 'price_changed';
        }
        if (preg_match('/inventory|stock|batch|movement/', $auditType)) {
            return 'inventory_changed';
        }
        if (preg_match('/production/', $auditType) && preg_match('/completed|posted|produced/', $auditType)) {
            return 'product_produced';
        }
        if (preg_match('/procurement|purchase|receipt/', $auditType) && preg_match('/received|created|posted/', $auditType)) {
            return 'supplier_purchase_received';
        }
        if (preg_match('/promotion.*redeem|coupon.*use/', $auditType)) {
            return 'coupon_used';
        }
        if (preg_match('/promotion|discount/', $auditType)) {
            return 'discount_applied';
        }
        if (preg_match('/sms/', $auditType) && preg_match('/sent|delivered/', $auditType)) {
            return 'sms_sent';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function resolveOrderId(string $aggregateType, string $aggregateId, array $payload): ?int
    {
        foreach (['order_id', 'sales_order_id'] as $field) {
            if (!empty($payload[$field])) {
                return (int) $payload[$field];
            }
        }
        if (str_contains(strtolower($aggregateType), 'order') && ctype_digit($aggregateId)) {
            return (int) $aggregateId;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function orderEventRows(
        int $orderId,
        string $eventType,
        string $auditId,
        string $occurredAt,
        ?int $actor,
        string $auditType,
        array $payload,
        ?string $correlation
    ): array {
        global $wpdb;
        $orders = $this->table('sales_orders');
        $lines = $this->table('sales_order_lines');
        $attribution = $this->table('order_attribution');
        $campaigns = $this->table('analytics_campaigns');
        $sources = $this->table('analytics_sources');
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, a.branch_id, a.salesperson_user_id, a.province, a.city, a.campaign_id,
                    COALESCE(s.code, o.channel) AS source_code
             FROM {$orders} o
             LEFT JOIN {$attribution} a ON a.order_id = o.id
             LEFT JOIN {$campaigns} c ON c.id = a.campaign_id
             LEFT JOIN {$sources} s ON s.id = COALESCE(a.source_id, c.source_id)
             WHERE o.id = %d",
            $orderId
        ), ARRAY_A);
        if (!is_array($order)) {
            return [];
        }
        $orderLines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$lines} WHERE order_id = %d ORDER BY id ASC",
            $orderId
        ), ARRAY_A);
        if (!is_array($orderLines) || $orderLines === []) {
            $orderLines = [[
                'product_id' => null,
                'quantity_scaled' => 0,
                'net_irr' => $order['subtotal_irr'],
                'line_discount_irr' => $order['line_discount_irr'],
                'cogs_irr' => $order['cogs_irr'],
            ]];
        }
        $group = $this->uuid();
        $rows = [];
        $lineCount = count($orderLines);
        $orderCogs = max(0, (int) ($order['cogs_irr'] ?? 0));
        $allocatedCogs = 0;
        foreach ($orderLines as $index => $line) {
            $lineRevenue = in_array($eventType, ['order_paid', 'order_returned'], true) ? max(0, (int) ($line['net_irr'] ?? 0)) : 0;
            $lineCogs = in_array($eventType, ['order_paid', 'order_returned'], true) ? max(0, (int) ($line['cogs_irr'] ?? 0)) : 0;
            if ($lineCogs === 0 && $orderCogs > 0 && $lineCount > 0) {
                $lineCogs = $index === $lineCount - 1
                    ? $orderCogs - $allocatedCogs
                    : intdiv($orderCogs, $lineCount);
                $allocatedCogs += $lineCogs;
            }
            if ($eventType === 'order_returned') {
                $lineRevenue = -$lineRevenue;
                $lineCogs = -$lineCogs;
            }
            $productId = $this->nullableInt($line['product_id'] ?? null);
            $dimensions = $productId !== null ? $this->productDimensions($productId) : [];
            $rows[] = [
                'event_key' => $this->uuid(),
                'event_group_key' => $group,
                'source_audit_event_id' => $auditId !== '' ? $auditId : null,
                'event_sequence' => $index,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'actor_user_id' => $actor,
                'branch_id' => $this->nullableInt($order['branch_id'] ?? null),
                'sales_channel' => (string) $order['channel'],
                'source_code' => (string) ($order['source_code'] ?: $order['channel']),
                'campaign_id' => $this->nullableInt($order['campaign_id'] ?? null),
                'customer_id' => (int) $order['customer_id'],
                'order_id' => $orderId,
                'product_id' => $productId,
                'product_line' => $dimensions['product_line'] ?? null,
                'quantity_scaled' => $eventType === 'order_returned'
                    ? -(int) ($line['quantity_scaled'] ?? 0)
                    : (int) ($line['quantity_scaled'] ?? 0),
                'revenue_irr' => $lineRevenue,
                'cogs_irr' => $lineCogs,
                'gross_profit_irr' => $lineRevenue - $lineCogs,
                'discount_irr' => max(0, (int) ($line['line_discount_irr'] ?? 0)),
                'order_count' => $index === 0 && in_array($eventType, ['order_created', 'order_paid'], true) ? 1 : 0,
                'province' => $order['province'] ?? null,
                'city' => $order['city'] ?? null,
                'aggregate_type' => 'sales_order',
                'aggregate_id' => (string) $orderId,
                'correlation_id' => $correlation ?: ($order['correlation_id'] ?? null),
                'payload' => ['audit_event_type' => $auditType, 'line_id' => $line['id'] ?? null] + $payload,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function productDimensions(int $productId): array
    {
        $configured = function_exists('get_option') ? get_option('rishe_product_analytics_dimensions', []) : [];
        if (!is_array($configured)) {
            return [];
        }
        $row = $configured[$productId] ?? $configured[(string) $productId] ?? [];

        return is_array($row) ? $row : [];
    }
}
