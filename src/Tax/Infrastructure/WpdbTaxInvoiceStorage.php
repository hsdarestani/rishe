<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Domain\Exception\TaxDomainException;
use RuntimeException;

trait WpdbTaxInvoiceStorage
{
    public function salesOrder(int $salesOrderId): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $lines = $wpdb->prefix . 'rishe_sales_order_lines';
        $payments = $wpdb->prefix . 'rishe_sales_payments';
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id = %d", $salesOrderId), ARRAY_A);
        if (!is_array($order)) {
            return null;
        }
        $order['id'] = (int) $order['id'];
        foreach (['shipping_irr', 'tax_irr', 'total_irr'] as $field) {
            $order[$field] = (int) $order[$field];
        }
        $orderLines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$lines} WHERE order_id = %d ORDER BY id",
            $salesOrderId
        ), ARRAY_A);
        $order['lines'] = is_array($orderLines) ? array_map(static function (array $line): array {
            foreach ([
                'id', 'order_id', 'product_id', 'quantity_scaled', 'unit_price_irr', 'gross_irr',
                'line_discount_irr', 'net_irr',
            ] as $field) {
                $line[$field] = (int) $line[$field];
            }

            return $line;
        }, $orderLines) : [];
        $paymentRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$payments} WHERE order_id = %d AND status = 'captured' ORDER BY id",
            $salesOrderId
        ), ARRAY_A);
        $order['payments'] = is_array($paymentRows) ? $paymentRows : [];

        return $order;
    }

    public function createInvoice(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_invoices';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['source_hash'] !== (string) $data['payload_hash']) {
                throw new TaxDomainException('Tax invoice idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }
        $now = current_time('mysql', true);
        $invoiceId = $this->insert('rishe_tax_invoices', [
            'public_id' => wp_generate_uuid4(),
            'profile_id' => $data['profile_id'],
            'sales_order_id' => $data['sales_order_id'],
            'source_invoice_id' => $data['source_invoice_id'],
            'derived_invoice_id' => null,
            'subject' => strtolower($data['subject']),
            'subject_code' => $data['subject_code'],
            'status' => $data['status'],
            'invoice_type' => $data['invoice_type'],
            'invoice_pattern' => $data['invoice_pattern'],
            'settlement_method' => $data['settlement_method'],
            'buyer_type' => $data['buyer']['buyer_type'],
            'buyer_name' => $data['buyer']['name'],
            'buyer_national_id' => $data['buyer']['national_id'],
            'buyer_economic_code' => $data['buyer']['economic_code'],
            'buyer_postal_code' => $data['buyer']['postal_code'],
            'buyer_branch_code' => $data['buyer']['branch_code'],
            'seller_national_id' => $data['seller']['national_id'],
            'seller_economic_code' => $data['seller']['economic_code'],
            'seller_branch_code' => $data['seller']['branch_code'],
            'gross_irr' => $data['totals']['gross_irr'],
            'discount_irr' => $data['totals']['discount_irr'],
            'net_irr' => $data['totals']['net_irr'],
            'vat_irr' => $data['totals']['vat_irr'],
            'other_duty_irr' => 0,
            'total_irr' => $data['totals']['total_irr'],
            'cash_irr' => $data['cash_irr'],
            'credit_irr' => $data['credit_irr'],
            'idempotency_key' => $data['idempotency_key'],
            'source_hash' => $data['payload_hash'],
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], [], 'tax invoice');
        foreach ($data['lines'] as $line) {
            $this->insert('rishe_tax_invoice_lines', [
                'tax_invoice_id' => $invoiceId,
                'sales_order_line_id' => $line['sales_order_line_id'],
                'product_id' => $line['product_id'],
                'tax_product_id' => $line['tax_product_id'],
                'description' => $line['description'],
                'measurement_unit' => $line['measurement_unit'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
                'gross_irr' => $line['gross_irr'],
                'discount_irr' => $line['discount_irr'],
                'net_irr' => $line['net_irr'],
                'vat_rate_basis_points' => $line['vat_rate_basis_points'],
                'vat_irr' => $line['vat_irr'],
                'other_duty_irr' => $line['other_duty_irr'],
                'total_irr' => $line['total_irr'],
                'created_at' => $now,
            ], [], 'tax invoice line');
        }
        foreach ($data['payments'] as $payment) {
            $this->insert('rishe_tax_invoice_payments', [
                'tax_invoice_id' => $invoiceId,
                'provider' => $payment['provider'] ?? null,
                'external_payment_id' => $payment['external_payment_id'] ?? null,
                'amount_irr' => (int) ($payment['amount_irr'] ?? 0),
                'captured_at' => $payment['captured_at'] ?? null,
                'created_at' => $now,
            ], [], 'tax invoice payment');
        }

        return ['id' => $invoiceId, 'idempotent' => false];
    }

    public function invoice(int $invoiceId): ?array
    {
        $row = $this->row('rishe_tax_invoices', $invoiceId);

        return $row === null ? null : $this->hydrateInvoice($row);
    }

    public function invoiceForUpdate(int $invoiceId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_invoices';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $invoiceId
        ), ARRAY_A);

        return is_array($row) ? $this->hydrateInvoice($row) : null;
    }

    public function invoices(array $filters): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_invoices';
        $clauses = ['1=1'];
        $args = [];
        foreach (['profile_id', 'sales_order_id', 'status', 'subject'] as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $format = str_ends_with($field, '_id') ? '%d' : '%s';
            $clauses[] = "{$field} = {$format}";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT 250';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatInvoice'], $rows) : [];
    }

    public function nextSerial(int $profileId, int $fiscalYear): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_sequences';
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (profile_id, fiscal_year, next_serial) VALUES (%d, %d, 1)",
            $profileId,
            $fiscalYear
        ));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE profile_id = %d AND fiscal_year = %d FOR UPDATE",
            $profileId,
            $fiscalYear
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to lock tax invoice sequence.');
        }
        $serial = (int) $row['next_serial'];
        if ($wpdb->update($table, ['next_serial' => $serial + 1], ['id' => $row['id']], ['%d'], ['%d']) !== 1) {
            throw new RuntimeException('Unable to increment tax invoice sequence.');
        }

        return $serial;
    }

    public function freezeInvoice(int $invoiceId, array $data): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_tax_invoices', [
            'status' => 'frozen',
            'fiscal_year' => $data['fiscal_year'],
            'internal_serial' => $data['internal_serial'],
            'tax_number' => $data['tax_number'],
            'issued_at' => $data['issued_at'],
            'payload_json' => $data['payload_json'],
            'payload_sha256' => $data['payload_sha256'],
            'signature' => $data['signature'],
            'frozen_by' => $data['frozen_by'],
            'frozen_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $invoiceId, 'status' => 'draft']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to freeze tax invoice.');
        }
    }

    public function createDerivedInvoice(int $sourceInvoiceId, string $subject, int $actorUserId): array
    {
        $source = $this->invoice($sourceInvoiceId);
        if ($source === null) {
            throw new RuntimeException('Source tax invoice not found.');
        }
        $subjectCode = match ($subject) {
            'correction' => 2,
            'cancellation' => 3,
            'return' => 4,
            default => throw new RuntimeException('Unsupported derived invoice subject.'),
        };
        $hash = hash('sha256', $source['source_hash'] . '|' . $subject . '|' . $sourceInvoiceId);
        $result = $this->createInvoice([
            'profile_id' => $source['profile_id'],
            'sales_order_id' => $source['sales_order_id'],
            'source_invoice_id' => $sourceInvoiceId,
            'subject' => $subject,
            'subject_code' => $subjectCode,
            'status' => 'draft',
            'invoice_type' => $source['invoice_type'],
            'invoice_pattern' => $source['invoice_pattern'],
            'settlement_method' => $source['settlement_method'],
            'buyer' => [
                'buyer_type' => $source['buyer_type'],
                'name' => $source['buyer_name'],
                'national_id' => $source['buyer_national_id'],
                'economic_code' => $source['buyer_economic_code'],
                'postal_code' => $source['buyer_postal_code'],
                'branch_code' => $source['buyer_branch_code'],
            ],
            'seller' => [
                'national_id' => $source['seller_national_id'],
                'economic_code' => $source['seller_economic_code'],
                'branch_code' => $source['seller_branch_code'],
            ],
            'totals' => [
                'gross_irr' => $source['gross_irr'],
                'discount_irr' => $source['discount_irr'],
                'net_irr' => $source['net_irr'],
                'vat_irr' => $source['vat_irr'],
                'total_irr' => $source['total_irr'],
            ],
            'cash_irr' => $source['cash_irr'],
            'credit_irr' => $source['credit_irr'],
            'idempotency_key' => 'derived-' . $subject . '-' . $sourceInvoiceId,
            'payload_hash' => $hash,
            'correlation_id' => $source['correlation_id'],
            'actor_user_id' => $actorUserId,
            'lines' => $source['lines'],
            'payments' => $source['payments'],
        ]);
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rishe_tax_invoices',
            ['source_tax_number' => $source['tax_number']],
            ['id' => $result['id']],
            ['%s'],
            ['%d']
        );

        return $result;
    }
}
