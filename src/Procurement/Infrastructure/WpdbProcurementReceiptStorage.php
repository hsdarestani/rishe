<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use RuntimeException;

trait WpdbProcurementReceiptStorage
{
    public function createReceipt(array $data): array
    {
        global $wpdb;

        $receipts = $wpdb->prefix . 'rishe_purchase_receipts';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$receipts} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                throw new ProcurementDomainException('Receipt idempotency key was reused with different inputs.');
            }

            return [
                'id' => (int) $existing['id'],
                'document_number' => (int) $existing['document_number'],
                'idempotent' => true,
                'line_ids' => [],
            ];
        }

        $now = current_time('mysql', true);
        $documentNumber = $this->nextDocumentNumber('purchase_receipt', (int) $data['fiscal_year']);
        $receiptId = $this->insert('rishe_purchase_receipts', [
            'public_id' => wp_generate_uuid4(),
            'fiscal_year' => $data['fiscal_year'],
            'document_number' => $documentNumber,
            'purchase_order_id' => $data['purchase_order_id'],
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'],
            'status' => 'posting',
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'merchandise_value_irr' => $data['merchandise_value_irr'],
            'tax_irr' => $data['tax_irr'],
            'landed_cost_irr' => $data['landed_cost_irr'],
            'liability_irr' => $data['liability_irr'],
            'received_at' => $data['received_at'],
            'due_date' => $data['due_date'],
            'reference' => $data['reference'],
            'notes' => $data['notes'],
            'accounting_status' => 'pending_configuration',
            'correlation_id' => $data['correlation_id'],
            'received_by' => $data['actor_user_id'],
            'created_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s',
            '%s', '%s', '%s', '%s', '%d', '%s',
        ], 'purchase receipt');

        $lineIds = [];
        $orderLines = $wpdb->prefix . 'rishe_purchase_order_lines';
        foreach ($data['lines'] as $line) {
            $lineId = $this->insert('rishe_purchase_receipt_lines', [
                'purchase_receipt_id' => $receiptId,
                'purchase_order_line_id' => $line['purchase_order_line_id'],
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'quantity_scaled' => $line['quantity_scaled'],
                'merchandise_value_irr' => $line['merchandise_value_irr'],
                'tax_irr' => $line['tax_irr'],
                'landed_cost_irr' => $line['landed_cost_irr'],
                'liability_irr' => $line['liability_irr'],
                'unit_cost_irr' => $line['unit_cost_irr'],
                'batch_code' => $line['batch_code'],
                'expiry_date' => $line['expiry_date'],
                'inventory_batch_id' => null,
                'created_at' => $now,
            ], [
                '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s',
            ], 'purchase-receipt line');
            $lineIds[] = $lineId;

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$orderLines}
                 SET received_quantity_scaled = received_quantity_scaled + %d,
                     received_inventory_value_irr = received_inventory_value_irr + %d,
                     received_tax_irr = received_tax_irr + %d
                 WHERE id = %d
                   AND received_quantity_scaled + %d <= quantity_scaled",
                $line['quantity_scaled'],
                $line['merchandise_value_irr'],
                $line['tax_irr'],
                $line['purchase_order_line_id'],
                $line['quantity_scaled']
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to update received purchase-order quantity.');
            }
        }

        foreach ($data['landed_costs'] as $cost) {
            $this->insert('rishe_purchase_landed_costs', [
                'purchase_receipt_id' => $receiptId,
                'cost_type' => $cost['cost_type'],
                'description' => $cost['description'],
                'amount_irr' => $cost['amount_irr'],
                'allocation_basis' => $cost['allocation_basis'],
                'created_at' => $now,
            ], ['%d', '%s', '%s', '%d', '%s', '%s'], 'landed cost');
        }

        $orders = $wpdb->prefix . 'rishe_purchase_orders';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$orders}
             SET received_merchandise_irr = received_merchandise_irr + %d,
                 received_tax_irr = received_tax_irr + %d,
                 received_landed_cost_irr = received_landed_cost_irr + %d,
                 received_liability_irr = received_liability_irr + %d,
                 updated_at = %s
             WHERE id = %d",
            $data['merchandise_value_irr'],
            $data['tax_irr'],
            $data['landed_cost_irr'],
            $data['liability_irr'],
            $now,
            $data['purchase_order_id']
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update purchase-order liability.');
        }

        $this->insert('rishe_supplier_ledger', [
            'public_id' => wp_generate_uuid4(),
            'supplier_id' => $data['supplier_id'],
            'purchase_order_id' => $data['purchase_order_id'],
            'purchase_receipt_id' => $receiptId,
            'purchase_payment_id' => null,
            'entry_type' => 'receipt_charge',
            'charge_irr' => $data['liability_irr'],
            'payment_irr' => 0,
            'due_date' => $data['due_date'],
            'description' => 'Liability from purchase receipt ' . $documentNumber,
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s'], 'supplier ledger entry');

        return [
            'id' => $receiptId,
            'document_number' => $documentNumber,
            'idempotent' => false,
            'line_ids' => $lineIds,
        ];
    }

    public function attachInventoryBatch(int $receiptLineId, int $inventoryBatchId): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_purchase_receipt_lines',
            ['inventory_batch_id' => $inventoryBatchId],
            ['id' => $receiptLineId, 'inventory_batch_id' => null],
            ['%d'],
            ['%d', '%d']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to attach inventory batch to purchase receipt.');
        }
    }

    public function finalizeReceipt(
        int $receiptId,
        int $purchaseOrderId,
        string $purchaseOrderStatus,
        ?array $accounting
    ): void {
        global $wpdb;

        $receiptData = [
            'status' => 'posted',
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
            'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
            'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
            'posted_at' => current_time('mysql', true),
        ];
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_purchase_receipts',
            $receiptData,
            ['id' => $receiptId, 'status' => 'posting'],
            ['%s', '%s', '%d', '%d', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to finalize purchase receipt.');
        }
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_purchase_orders',
            ['status' => $purchaseOrderStatus, 'updated_at' => current_time('mysql', true)],
            ['id' => $purchaseOrderId],
            ['%s', '%s'],
            ['%d']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update purchase-order receipt status.');
        }
    }

    public function receipt(int $receiptId): ?array
    {
        global $wpdb;

        $receipt = $this->row('rishe_purchase_receipts', $receiptId);
        if ($receipt === null) {
            return null;
        }
        $linesTable = $wpdb->prefix . 'rishe_purchase_receipt_lines';
        $costsTable = $wpdb->prefix . 'rishe_purchase_landed_costs';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE purchase_receipt_id = %d ORDER BY id",
            $receiptId
        ), ARRAY_A);
        $costs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$costsTable} WHERE purchase_receipt_id = %d ORDER BY id",
            $receiptId
        ), ARRAY_A);
        $receipt = $this->formatReceipt($receipt);
        $receipt['lines'] = is_array($lines) ? array_map([$this, 'formatReceiptLine'], $lines) : [];
        $receipt['landed_costs'] = is_array($costs) ? array_map([$this, 'formatCost'], $costs) : [];

        return $receipt;
    }

    public function receipts(array $filters): array
    {
        return array_map([$this, 'formatReceipt'], $this->simpleList(
            'rishe_purchase_receipts',
            $filters,
            ['purchase_order_id', 'supplier_id', 'warehouse_id']
        ));
    }
}
