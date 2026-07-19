<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use RuntimeException;

trait WpdbB2BMasterStorage
{
    public function customer(int $customerId): ?array
    {
        $row = $this->row('rishe_customers', $customerId);
        if ($row === null) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['loyalty_balance'] = (int) $row['loyalty_balance'];

        return $row;
    }

    public function warehouse(int $warehouseId): ?array
    {
        $row = $this->row('rishe_warehouses', $warehouseId);
        if ($row === null) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }

    public function product(int $productId): ?array
    {
        $row = $this->row('rishe_products', $productId);
        if ($row === null) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }

    public function upsertAccount(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_b2b_accounts';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s OR customer_id = %d LIMIT 1 FOR UPDATE",
            $data['code'],
            $data['customer_id']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        if (is_array($existing)) {
            $updated = $wpdb->update($table, [
                'customer_id' => $data['customer_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'account_type' => $data['account_type'],
                'consignment_warehouse_id' => $data['consignment_warehouse_id'],
                'credit_limit_irr' => $data['credit_limit_irr'],
                'commission_rate_bps' => $data['commission_rate_bps'],
                'settlement_terms_days' => $data['settlement_terms_days'],
                'receivable_subsidiary_ledger_id' => $data['receivable_subsidiary_ledger_id'],
                'floating_detail_id' => $data['floating_detail_id'],
                'updated_at' => $now,
            ], ['id' => $existing['id']], [
                '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s',
            ], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('Unable to update B2B account: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $id = $this->insert('rishe_b2b_accounts', [
            'public_id' => wp_generate_uuid4(),
            'customer_id' => $data['customer_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'consignment_warehouse_id' => $data['consignment_warehouse_id'],
            'credit_limit_irr' => $data['credit_limit_irr'],
            'current_receivable_irr' => 0,
            'commission_rate_bps' => $data['commission_rate_bps'],
            'settlement_terms_days' => $data['settlement_terms_days'],
            'receivable_subsidiary_ledger_id' => $data['receivable_subsidiary_ledger_id'],
            'floating_detail_id' => $data['floating_detail_id'],
            'status' => 'active',
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s',
        ], 'B2B account');

        return ['id' => $id, 'created' => true];
    }

    public function account(int $accountId): ?array
    {
        $row = $this->row('rishe_b2b_accounts', $accountId);

        return $row === null ? null : $this->formatAccount($row);
    }

    public function accountForUpdate(int $accountId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_b2b_accounts';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $accountId
        ), ARRAY_A);

        return is_array($row) ? $this->formatAccount($row) : null;
    }

    public function accounts(array $filters): array
    {
        return array_map([$this, 'formatAccount'], $this->simpleList(
            'rishe_b2b_accounts',
            $filters,
            ['account_type', 'status']
        ));
    }

    public function nextDocumentNumber(string $type, int $fiscalYear): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_b2b_sequences';
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
            throw new RuntimeException('Unable to lock B2B document sequence.');
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
            throw new RuntimeException('Unable to increment B2B document sequence.');
        }

        return $number;
    }
}
