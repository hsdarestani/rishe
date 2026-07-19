<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use RuntimeException;

trait WpdbProcurementSupplierStorage
{
    public function upsertSupplier(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_suppliers';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s FOR UPDATE",
            $data['code']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        if (is_array($existing)) {
            $updated = $wpdb->update($table, [
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'email' => $data['email'],
                'national_id' => $data['national_id'],
                'economic_code' => $data['economic_code'],
                'tax_id' => $data['tax_id'],
                'iban' => $data['iban'],
                'payment_terms_days' => $data['payment_terms_days'],
                'credit_limit_irr' => $data['credit_limit_irr'],
                'payable_subsidiary_ledger_id' => $data['payable_subsidiary_ledger_id'],
                'floating_detail_id' => $data['floating_detail_id'],
                'updated_at' => $now,
            ], ['id' => $existing['id']], [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s',
            ], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('Unable to update supplier: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $id = $this->insert('rishe_suppliers', [
            'public_id' => wp_generate_uuid4(),
            'code' => $data['code'],
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => $data['email'],
            'national_id' => $data['national_id'],
            'economic_code' => $data['economic_code'],
            'tax_id' => $data['tax_id'],
            'iban' => $data['iban'],
            'payment_terms_days' => $data['payment_terms_days'],
            'credit_limit_irr' => $data['credit_limit_irr'],
            'payable_subsidiary_ledger_id' => $data['payable_subsidiary_ledger_id'],
            'floating_detail_id' => $data['floating_detail_id'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ], 'supplier');

        return ['id' => $id, 'created' => true];
    }

    public function supplier(int $supplierId): ?array
    {
        $row = $this->row('rishe_suppliers', $supplierId);

        return $row === null ? null : $this->formatSupplier($row);
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

    public function suppliers(array $filters): array
    {
        return array_map([$this, 'formatSupplier'], $this->simpleList(
            'rishe_suppliers',
            $filters,
            ['is_active']
        ));
    }
}
