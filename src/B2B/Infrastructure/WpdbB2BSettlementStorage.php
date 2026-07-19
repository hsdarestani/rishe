<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use RuntimeException;

trait WpdbB2BSettlementStorage
{
    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_b2b_settlements';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE treasury_transaction_id = %d FOR UPDATE",
            $treasuryTransactionId
        ), ARRAY_A);

        return is_array($row) ? $this->formatSettlement($row) : null;
    }

    public function recordSettlement(
        int $accountId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        global $wpdb;

        $existing = $this->settlementByTreasuryTransaction($treasuryTransactionId);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $now = current_time('mysql', true);
        $settlementId = $this->insert('rishe_b2b_settlements', [
            'public_id' => wp_generate_uuid4(),
            'account_id' => $accountId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
            'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
            'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
            'settled_by' => $actorUserId,
            'settled_at' => $now,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s'], 'B2B settlement');

        $accounts = $wpdb->prefix . 'rishe_b2b_accounts';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$accounts}
             SET current_receivable_irr = current_receivable_irr - %d, updated_at = %s
             WHERE id = %d AND current_receivable_irr >= %d",
            $amountIrr,
            $now,
            $accountId,
            $amountIrr
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to reduce B2B receivable during settlement.');
        }
        $this->insert('rishe_b2b_ledger', [
            'public_id' => wp_generate_uuid4(),
            'account_id' => $accountId,
            'sales_report_id' => null,
            'settlement_id' => $settlementId,
            'entry_type' => 'settlement',
            'charge_irr' => 0,
            'payment_irr' => $amountIrr,
            'due_date' => null,
            'description' => 'Settlement from treasury transaction ' . $treasuryTransactionId,
            'actor_user_id' => $actorUserId,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s'], 'B2B ledger settlement');

        return ['id' => $settlementId, 'idempotent' => false];
    }

    public function statement(int $accountId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_b2b_ledger';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE account_id = %d ORDER BY created_at, id",
            $accountId
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $integerFields = [
            'id', 'account_id', 'sales_report_id', 'settlement_id', 'charge_irr', 'payment_irr', 'actor_user_id',
        ];
        $balance = 0;
        foreach ($rows as &$row) {
            foreach ($integerFields as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $balance += (int) $row['charge_irr'] - (int) $row['payment_irr'];
            $row['balance_irr'] = $balance;
        }
        unset($row);

        return $rows;
    }
}
