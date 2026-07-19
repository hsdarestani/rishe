<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use RuntimeException;

trait WpdbTaxStorageHelpers
{
    private function insert(string $suffix, array $data, array $formats, string $entity): int
    {
        global $wpdb;

        if ($wpdb->insert($wpdb->prefix . $suffix, $data, $formats) === false) {
            throw new RuntimeException('Unable to create ' . $entity . ': ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    private function row(string $suffix, int $id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function formatProfile(array $row): array
    {
        foreach (['id', 'taxpayer_type', 'default_invoice_type', 'default_pattern', 'created_by'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['is_active'] = (bool) $row['is_active'];
        $row['gateway_config'] = json_decode((string) $row['gateway_config_json'], true) ?: [];
        unset($row['credentials_ciphertext'], $row['private_key_ciphertext']);

        return $row;
    }

    private function formatMapping(array $row): array
    {
        foreach (['id', 'profile_id', 'product_id', 'vat_rate_basis_points', 'created_by'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }

    private function formatInvoice(array $row): array
    {
        $nullable = [
            'sales_order_id', 'source_invoice_id', 'derived_invoice_id', 'fiscal_year', 'internal_serial',
            'frozen_by',
        ];
        $integer = [
            'id', 'profile_id', 'subject_code', 'invoice_type', 'invoice_pattern', 'settlement_method',
            'buyer_type', 'gross_irr', 'discount_irr', 'net_irr', 'vat_irr', 'other_duty_irr',
            'total_irr', 'cash_irr', 'credit_irr', 'submission_attempts', 'created_by',
        ];
        foreach ($integer as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach ($nullable as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    private function hydrateInvoice(array $row): array
    {
        global $wpdb;

        $invoice = $this->formatInvoice($row);
        $linesTable = $wpdb->prefix . 'rishe_tax_invoice_lines';
        $paymentsTable = $wpdb->prefix . 'rishe_tax_invoice_payments';
        $submissionsTable = $wpdb->prefix . 'rishe_tax_submissions';
        $eventsTable = $wpdb->prefix . 'rishe_tax_status_events';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE tax_invoice_id = %d ORDER BY id",
            $invoice['id']
        ), ARRAY_A);
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$paymentsTable} WHERE tax_invoice_id = %d ORDER BY id",
            $invoice['id']
        ), ARRAY_A);
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$submissionsTable} WHERE tax_invoice_id = %d ORDER BY attempt_number DESC",
            $invoice['id']
        ), ARRAY_A);
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$eventsTable} WHERE tax_invoice_id = %d ORDER BY id DESC LIMIT 100",
            $invoice['id']
        ), ARRAY_A);
        $invoice['lines'] = is_array($lines) ? array_map([$this, 'formatInvoiceLine'], $lines) : [];
        $invoice['payments'] = is_array($payments) ? array_map([$this, 'formatPayment'], $payments) : [];
        $invoice['submissions'] = is_array($submissions) ? $submissions : [];
        $invoice['status_events'] = is_array($events) ? $events : [];

        return $invoice;
    }

    private function formatInvoiceLine(array $row): array
    {
        $fields = [
            'id', 'tax_invoice_id', 'sales_order_line_id', 'product_id', 'quantity_scaled',
            'unit_price_irr', 'gross_irr', 'discount_irr', 'net_irr', 'vat_rate_basis_points',
            'vat_irr', 'other_duty_irr', 'total_irr',
        ];
        foreach ($fields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    private function formatPayment(array $row): array
    {
        foreach (['id', 'tax_invoice_id', 'amount_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }
}
