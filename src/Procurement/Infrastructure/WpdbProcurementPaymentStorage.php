<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use RuntimeException;

trait WpdbProcurementPaymentStorage
{
    public function paymentByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_purchase_payments';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE treasury_transaction_id = %d FOR UPDATE",
            $treasuryTransactionId
        ), ARRAY_A);

        return is_array($row) ? $this->formatPayment($row) : null;
    }

    public function recordPayment(
        int $purchaseOrderId,
        int $supplierId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        global $wpdb;

        $existing = $this->paymentByTreasuryTransaction($treasuryTransactionId);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $now = current_time('mysql', true);
        $paymentId = $this->insert('rishe_purchase_payments', [
            'public_id' => wp_generate_uuid4(),
            'purchase_order_id' => $purchaseOrderId,
            'supplier_id' => $supplierId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
            'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
            'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
            'paid_by' => $actorUserId,
            'paid_at' => $now,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s'], 'purchase payment');

        $orders = $wpdb->prefix . 'rishe_purchase_orders';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$orders}
             SET paid_irr = paid_irr + %d, updated_at = %s
             WHERE id = %d AND paid_irr + %d <= received_liability_irr",
            $amountIrr,
            $now,
            $purchaseOrderId,
            $amountIrr
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update purchase-order paid balance.');
        }
        $this->insert('rishe_supplier_ledger', [
            'public_id' => wp_generate_uuid4(),
            'supplier_id' => $supplierId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_receipt_id' => null,
            'purchase_payment_id' => $paymentId,
            'entry_type' => 'payment',
            'charge_irr' => 0,
            'payment_irr' => $amountIrr,
            'due_date' => null,
            'description' => 'Supplier payment from treasury transaction ' . $treasuryTransactionId,
            'actor_user_id' => $actorUserId,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s'], 'supplier ledger payment');

        return ['id' => $paymentId, 'idempotent' => false];
    }

    public function supplierStatement(int $supplierId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_supplier_ledger';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE supplier_id = %d ORDER BY created_at, id",
            $supplierId
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $balance = 0;
        foreach ($rows as &$row) {
            foreach ([
                'id', 'supplier_id', 'purchase_order_id', 'purchase_receipt_id', 'purchase_payment_id',
                'charge_irr', 'payment_irr', 'actor_user_id',
            ] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $balance += (int) $row['charge_irr'] - (int) $row['payment_irr'];
            $row['balance_irr'] = $balance;
        }
        unset($row);

        return $rows;
    }
}
