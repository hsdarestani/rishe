<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use RuntimeException;

trait WpdbProcurementOrderStorage
{
    public function createPurchaseOrder(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_purchase_orders';
        $clauses = [];
        $args = [];
        if ($data['idempotency_key'] !== null) {
            $clauses[] = 'idempotency_key = %s';
            $args[] = $data['idempotency_key'];
        }
        if ($data['external_reference'] !== null) {
            $clauses[] = '(supplier_id = %d AND external_reference = %s)';
            $args[] = $data['supplier_id'];
            $args[] = $data['external_reference'];
        }
        if ($clauses !== []) {
            $sql = "SELECT * FROM {$table} WHERE " . implode(' OR ', $clauses) . ' LIMIT 1 FOR UPDATE';
            $existing = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
            if (is_array($existing)) {
                if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                    throw new ProcurementDomainException(
                        'Purchase-order reference was reused with different commercial inputs.'
                    );
                }

                return ['id' => (int) $existing['id'], 'idempotent' => true];
            }
        }

        $now = current_time('mysql', true);
        $orderId = $this->insert('rishe_purchase_orders', [
            'public_id' => wp_generate_uuid4(),
            'fiscal_year' => $data['fiscal_year'],
            'document_number' => null,
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'],
            'status' => $data['status'],
            'external_reference' => $data['external_reference'],
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'expected_at' => $data['expected_at'],
            'notes' => $data['notes'],
            'merchandise_gross_irr' => $data['merchandise_gross_irr'],
            'discount_irr' => $data['discount_irr'],
            'merchandise_net_irr' => $data['merchandise_net_irr'],
            'tax_irr' => $data['tax_irr'],
            'estimated_landed_cost_irr' => $data['estimated_landed_cost_irr'],
            'estimated_total_irr' => $data['estimated_total_irr'],
            'payment_terms_days' => $data['payment_terms_days'],
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%s', '%d', '%s', '%s',
        ], 'purchase order');

        foreach ($data['lines'] as $line) {
            $this->insert('rishe_purchase_order_lines', [
                'purchase_order_id' => $orderId,
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
                'gross_irr' => $line['gross_irr'],
                'discount_irr' => $line['discount_irr'],
                'inventory_value_irr' => $line['inventory_value_irr'],
                'tax_irr' => $line['tax_irr'],
                'line_total_irr' => $line['line_total_irr'],
                'description' => $line['description'],
                'created_at' => $now,
            ], [
                '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s',
            ], 'purchase-order line');
        }

        return ['id' => $orderId, 'idempotent' => false];
    }

    public function purchaseOrderForUpdate(int $purchaseOrderId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_purchase_orders';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $purchaseOrderId
        ), ARRAY_A);

        return is_array($row) ? $this->hydratePurchaseOrder($row) : null;
    }

    public function purchaseOrder(int $purchaseOrderId): ?array
    {
        $row = $this->row('rishe_purchase_orders', $purchaseOrderId);

        return $row === null ? null : $this->hydratePurchaseOrder($row);
    }

    public function nextDocumentNumber(string $type, int $fiscalYear): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_purchase_sequences';
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (sequence_type, fiscal_year, next_number) VALUES (%s, %d, 1)",
            $type,
            $fiscalYear
        ));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE sequence_type = %s AND fiscal_year = %d FOR UPDATE",
            $type,
            $fiscalYear
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to lock purchase document sequence.');
        }
        $number = (int) $row['next_number'];
        $updated = $wpdb->update(
            $table,
            ['next_number' => $number + 1],
            ['id' => $row['id']],
            ['%d'],
            ['%d']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to increment purchase document sequence.');
        }

        return $number;
    }

    public function approvePurchaseOrder(
        int $purchaseOrderId,
        int $documentNumber,
        int $actorUserId,
        string $approvedAt
    ): void {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_purchase_orders', [
            'document_number' => $documentNumber,
            'status' => 'approved',
            'approved_by' => $actorUserId,
            'approved_at' => $approvedAt,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $purchaseOrderId, 'status' => 'draft'], ['%d', '%s', '%d', '%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to approve purchase order.');
        }
    }

    public function cancelPurchaseOrder(int $purchaseOrderId, int $actorUserId, string $reason): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_purchase_orders', [
            'status' => 'cancelled',
            'cancelled_by' => $actorUserId,
            'cancelled_at' => current_time('mysql', true),
            'cancellation_reason' => $reason,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $purchaseOrderId], ['%s', '%d', '%s', '%s', '%s'], ['%d']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to cancel purchase order.');
        }
    }

    public function purchaseOrders(array $filters): array
    {
        return array_map([$this, 'formatPurchaseOrder'], $this->simpleList(
            'rishe_purchase_orders',
            $filters,
            ['supplier_id', 'warehouse_id', 'status']
        ));
    }

    /** @return array<string, mixed> */
    private function hydratePurchaseOrder(array $row): array
    {
        global $wpdb;

        $order = $this->formatPurchaseOrder($row);
        $linesTable = $wpdb->prefix . 'rishe_purchase_order_lines';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE purchase_order_id = %d ORDER BY id",
            $order['id']
        ), ARRAY_A);
        $order['lines'] = is_array($lines) ? array_map([$this, 'formatOrderLine'], $lines) : [];
        $supplier = $this->supplier((int) $order['supplier_id']);
        $order['supplier_name'] = $supplier['name'] ?? null;
        $order['supplier_floating_detail_id'] = $supplier['floating_detail_id'] ?? null;
        $order['supplier_payable_subsidiary_ledger_id'] = $supplier['payable_subsidiary_ledger_id'] ?? null;
        $order['outstanding_irr'] = (int) $order['received_liability_irr'] - (int) $order['paid_irr'];

        return $order;
    }
}
