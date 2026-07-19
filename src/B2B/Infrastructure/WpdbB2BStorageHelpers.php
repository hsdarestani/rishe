<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use RuntimeException;

trait WpdbB2BStorageHelpers
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
            $format = str_ends_with($field, '_id') ? '%d' : '%s';
            $clauses[] = "{$field} = {$format}";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT 250';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatAccount(array $row): array
    {
        $integerFields = [
            'id', 'customer_id', 'consignment_warehouse_id', 'credit_limit_irr', 'current_receivable_irr',
            'commission_rate_bps', 'settlement_terms_days', 'receivable_subsidiary_ledger_id',
            'floating_detail_id', 'created_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatDispatch(array $row): array
    {
        $integerFields = [
            'id', 'fiscal_year', 'document_number', 'account_id', 'source_warehouse_id',
            'destination_warehouse_id', 'dispatched_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatDispatchLine(array $row): array
    {
        $integerFields = [
            'id', 'dispatch_id', 'product_id', 'quantity_scaled', 'sold_quantity_scaled',
            'returned_quantity_scaled',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['open_quantity_scaled'] = $row['quantity_scaled']
            - $row['sold_quantity_scaled']
            - $row['returned_quantity_scaled'];

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatReturn(array $row): array
    {
        $integerFields = [
            'id', 'fiscal_year', 'document_number', 'dispatch_id', 'account_id', 'source_warehouse_id',
            'destination_warehouse_id', 'returned_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatReturnLine(array $row): array
    {
        foreach (['id', 'return_id', 'dispatch_line_id', 'product_id', 'quantity_scaled'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatSalesReport(array $row): array
    {
        $integerFields = [
            'id', 'fiscal_year', 'document_number', 'account_id', 'warehouse_id', 'gross_irr',
            'commission_irr', 'receivable_irr', 'cogs_irr', 'accounting_voucher_id',
            'accounting_voucher_number', 'reported_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatSalesReportLine(array $row): array
    {
        $integerFields = [
            'id', 'sales_report_id', 'product_id', 'quantity_scaled', 'unit_price_irr', 'gross_irr',
            'commission_rate_bps', 'commission_irr', 'receivable_irr', 'reservation_id', 'cogs_irr',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatAllocation(array $row): array
    {
        foreach (['id', 'sales_report_line_id', 'dispatch_line_id', 'quantity_scaled'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatSettlement(array $row): array
    {
        $integerFields = [
            'id', 'account_id', 'treasury_transaction_id', 'amount_irr', 'accounting_voucher_id',
            'accounting_voucher_number', 'settled_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }
}
