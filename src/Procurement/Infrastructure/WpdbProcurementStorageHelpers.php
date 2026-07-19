<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use RuntimeException;

trait WpdbProcurementStorageHelpers
{
    /** @return array<string, mixed>|null */
    private function row(string $suffix, int $id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
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

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowed
     * @return list<array<string, mixed>>
     */
    private function simpleList(string $suffix, array $filters, array $allowed): array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $clauses = ['1=1'];
        $args = [];
        foreach ($allowed as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $format = str_ends_with($field, '_id') || $field === 'is_active' ? '%d' : '%s';
            $clauses[] = "{$field} = {$format}";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT 250';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatSupplier(array $row): array
    {
        $integerFields = [
            'id', 'payment_terms_days', 'credit_limit_irr', 'payable_subsidiary_ledger_id',
            'floating_detail_id', 'created_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatPurchaseOrder(array $row): array
    {
        $integerFields = [
            'id', 'fiscal_year', 'document_number', 'supplier_id', 'warehouse_id', 'merchandise_gross_irr',
            'discount_irr', 'merchandise_net_irr', 'tax_irr', 'estimated_landed_cost_irr',
            'estimated_total_irr', 'payment_terms_days', 'received_merchandise_irr', 'received_tax_irr',
            'received_landed_cost_irr', 'received_liability_irr', 'paid_irr', 'created_by', 'approved_by',
            'cancelled_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatOrderLine(array $row): array
    {
        $integerFields = [
            'id', 'purchase_order_id', 'product_id', 'quantity_scaled', 'unit_price_irr', 'gross_irr',
            'discount_irr', 'inventory_value_irr', 'tax_irr', 'line_total_irr', 'received_quantity_scaled',
            'received_inventory_value_irr', 'received_tax_irr',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatReceipt(array $row): array
    {
        $integerFields = [
            'id', 'fiscal_year', 'document_number', 'purchase_order_id', 'supplier_id', 'warehouse_id',
            'merchandise_value_irr', 'tax_irr', 'landed_cost_irr', 'liability_irr',
            'accounting_voucher_id', 'accounting_voucher_number', 'received_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatReceiptLine(array $row): array
    {
        $integerFields = [
            'id', 'purchase_receipt_id', 'purchase_order_line_id', 'product_id', 'quantity_scaled',
            'merchandise_value_irr', 'tax_irr', 'landed_cost_irr', 'liability_irr', 'unit_cost_irr',
            'inventory_batch_id',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatCost(array $row): array
    {
        foreach (['id', 'purchase_receipt_id', 'amount_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatPayment(array $row): array
    {
        $integerFields = [
            'id', 'purchase_order_id', 'supplier_id', 'treasury_transaction_id', 'amount_irr',
            'accounting_voucher_id', 'accounting_voucher_number', 'paid_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }
}
