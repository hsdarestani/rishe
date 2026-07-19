<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait WpdbB2BSalesStorage
{
    public function createSalesReport(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_agent_sales_reports';
        $clauses = ['idempotency_key = %s'];
        $args = [$data['idempotency_key']];
        if ($data['external_reference'] !== null) {
            $clauses[] = '(account_id = %d AND external_reference = %s)';
            $args[] = $data['account_id'];
            $args[] = $data['external_reference'];
        }
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE " . implode(' OR ', $clauses) . ' LIMIT 1 FOR UPDATE',
            ...$args
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                throw new B2BDomainException('Sales-report reference was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true, 'line_ids' => []];
        }

        $now = current_time('mysql', true);
        $reportId = $this->insert('rishe_agent_sales_reports', [
            'public_id' => wp_generate_uuid4(),
            'fiscal_year' => $data['fiscal_year'],
            'document_number' => $data['document_number'],
            'account_id' => $data['account_id'],
            'warehouse_id' => $data['warehouse_id'],
            'status' => 'posting',
            'external_reference' => $data['external_reference'],
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'gross_irr' => $data['gross_irr'],
            'commission_irr' => $data['commission_irr'],
            'receivable_irr' => $data['receivable_irr'],
            'cogs_irr' => null,
            'due_date' => null,
            'notes' => $data['notes'],
            'accounting_status' => 'pending_configuration',
            'correlation_id' => $data['correlation_id'],
            'reported_by' => $data['actor_user_id'],
            'reported_at' => $data['reported_at'],
            'created_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s',
            '%s', '%s', '%d', '%s', '%s',
        ], 'agent sales report');

        $lineIds = [];
        foreach ($data['lines'] as $line) {
            $lineIds[] = $this->insert('rishe_agent_sales_report_lines', [
                'sales_report_id' => $reportId,
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
                'gross_irr' => $line['gross_irr'],
                'commission_rate_bps' => $line['commission_rate_bps'],
                'commission_irr' => $line['commission_irr'],
                'receivable_irr' => $line['receivable_irr'],
                'reservation_id' => null,
                'cogs_irr' => null,
                'created_at' => $now,
            ], ['%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s'], 'sales-report line');
        }

        return ['id' => $reportId, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function allocateSoldQuantity(
        int $reportLineId,
        int $accountId,
        int $productId,
        int $quantityScaled
    ): array {
        global $wpdb;

        $dispatches = $wpdb->prefix . 'rishe_consignment_dispatches';
        $lines = $wpdb->prefix . 'rishe_consignment_dispatch_lines';
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, d.id AS parent_dispatch_id
             FROM {$lines} l
             INNER JOIN {$dispatches} d ON d.id = l.dispatch_id
             WHERE d.account_id = %d
               AND d.status IN ('active', 'partially_settled')
               AND l.product_id = %d
               AND l.quantity_scaled > l.sold_quantity_scaled + l.returned_quantity_scaled
             ORDER BY d.dispatched_at, d.id, l.id
             FOR UPDATE",
            $accountId,
            $productId
        ), ARRAY_A);
        if (!is_array($candidates)) {
            $candidates = [];
        }
        $remaining = $quantityScaled;
        $allocations = [];
        $touchedDispatches = [];
        foreach ($candidates as $candidate) {
            if ($remaining === 0) {
                break;
            }
            $available = (int) $candidate['quantity_scaled']
                - (int) $candidate['sold_quantity_scaled']
                - (int) $candidate['returned_quantity_scaled'];
            $allocated = min($available, $remaining);
            if ($allocated < 1) {
                continue;
            }
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$lines}
                 SET sold_quantity_scaled = sold_quantity_scaled + %d
                 WHERE id = %d
                   AND sold_quantity_scaled + returned_quantity_scaled + %d <= quantity_scaled",
                $allocated,
                $candidate['id'],
                $allocated
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to allocate consignment sold quantity.');
            }
            $this->insert('rishe_consignment_sale_allocations', [
                'sales_report_line_id' => $reportLineId,
                'dispatch_line_id' => $candidate['id'],
                'quantity_scaled' => $allocated,
                'created_at' => current_time('mysql', true),
            ], ['%d', '%d', '%d', '%s'], 'consignment sale allocation');
            $allocations[] = [
                'dispatch_line_id' => (int) $candidate['id'],
                'quantity_scaled' => $allocated,
            ];
            $touchedDispatches[(int) $candidate['parent_dispatch_id']] = true;
            $remaining -= $allocated;
        }
        if ($remaining > 0) {
            throw new B2BDomainException('Reported sale exceeds open consignment dispatch quantities.');
        }

        foreach (array_keys($touchedDispatches) as $dispatchId) {
            $open = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$lines}
                 WHERE dispatch_id = %d
                   AND sold_quantity_scaled + returned_quantity_scaled < quantity_scaled",
                $dispatchId
            ));
            $wpdb->update(
                $dispatches,
                ['status' => $open === 0 ? 'closed' : 'partially_settled'],
                ['id' => $dispatchId],
                ['%s'],
                ['%d']
            );
        }

        return $allocations;
    }

    public function attachSalesConsumption(int $reportLineId, int $reservationId, int $cogsIrr): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_agent_sales_report_lines',
            ['reservation_id' => $reservationId, 'cogs_irr' => $cogsIrr],
            ['id' => $reportLineId, 'reservation_id' => null],
            ['%d', '%d'],
            ['%d', '%d']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to attach agent sales inventory consumption.');
        }
    }

    public function finalizeSalesReport(
        int $reportId,
        int $accountId,
        int $receivableIrr,
        int $cogsIrr,
        string $dueDate,
        ?array $accounting
    ): void {
        global $wpdb;

        $accounts = $wpdb->prefix . 'rishe_b2b_accounts';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$accounts}
             SET current_receivable_irr = current_receivable_irr + %d, updated_at = %s
             WHERE id = %d AND current_receivable_irr + %d <= credit_limit_irr",
            $receivableIrr,
            current_time('mysql', true),
            $accountId,
            $receivableIrr
        ));
        if ($updated !== 1) {
            throw new B2BDomainException('B2B credit limit was exceeded during report finalization.');
        }
        $reportData = [
            'status' => 'posted',
            'cogs_irr' => $cogsIrr,
            'due_date' => $dueDate,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
            'accounting_voucher_id' => $accounting['voucher_id'] ?? null,
            'accounting_voucher_number' => $accounting['voucher_number'] ?? null,
            'posted_at' => current_time('mysql', true),
        ];
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_agent_sales_reports',
            $reportData,
            ['id' => $reportId, 'status' => 'posting'],
            ['%s', '%d', '%s', '%s', '%d', '%d', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to finalize agent sales report.');
        }
        $this->insert('rishe_b2b_ledger', [
            'public_id' => wp_generate_uuid4(),
            'account_id' => $accountId,
            'sales_report_id' => $reportId,
            'settlement_id' => null,
            'entry_type' => 'sales_charge',
            'charge_irr' => $receivableIrr,
            'payment_irr' => 0,
            'due_date' => $dueDate,
            'description' => 'Receivable from agent sales report ' . $reportId,
            'actor_user_id' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s'], 'B2B ledger charge');
    }

    public function salesReport(int $reportId): ?array
    {
        global $wpdb;

        $report = $this->row('rishe_agent_sales_reports', $reportId);
        if ($report === null) {
            return null;
        }
        $linesTable = $wpdb->prefix . 'rishe_agent_sales_report_lines';
        $allocationTable = $wpdb->prefix . 'rishe_consignment_sale_allocations';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE sales_report_id = %d ORDER BY id",
            $reportId
        ), ARRAY_A);
        $report = $this->formatSalesReport($report);
        $report['lines'] = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = $this->formatSalesReportLine($line);
                $allocations = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$allocationTable} WHERE sales_report_line_id = %d ORDER BY id",
                    $line['id']
                ), ARRAY_A);
                $line['dispatch_allocations'] = is_array($allocations)
                    ? array_map([$this, 'formatAllocation'], $allocations)
                    : [];
                $report['lines'][] = $line;
            }
        }

        return $report;
    }

    public function salesReports(array $filters): array
    {
        return array_map([$this, 'formatSalesReport'], $this->simpleList(
            'rishe_agent_sales_reports',
            $filters,
            ['account_id', 'status']
        ));
    }
}
