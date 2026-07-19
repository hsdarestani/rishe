<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait WpdbB2BDispatchStorage
{
    public function createDispatch(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_consignment_dispatches';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                throw new B2BDomainException('Dispatch idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true, 'line_ids' => []];
        }

        $now = current_time('mysql', true);
        $dispatchId = $this->insert('rishe_consignment_dispatches', [
            'public_id' => wp_generate_uuid4(),
            'fiscal_year' => $data['fiscal_year'],
            'document_number' => $data['document_number'],
            'account_id' => $data['account_id'],
            'source_warehouse_id' => $data['source_warehouse_id'],
            'destination_warehouse_id' => $data['destination_warehouse_id'],
            'status' => 'posting',
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'reference' => $data['reference'],
            'notes' => $data['notes'],
            'correlation_id' => $data['correlation_id'],
            'dispatched_by' => $data['actor_user_id'],
            'dispatched_at' => $data['dispatched_at'],
            'created_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s',
        ], 'consignment dispatch');

        $lineIds = [];
        foreach ($data['lines'] as $line) {
            $lineIds[] = $this->insert('rishe_consignment_dispatch_lines', [
                'dispatch_id' => $dispatchId,
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'quantity_scaled' => $line['quantity_scaled'],
                'sold_quantity_scaled' => 0,
                'returned_quantity_scaled' => 0,
                'transfer_group_id' => null,
                'created_at' => $now,
            ], ['%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s'], 'consignment dispatch line');
        }

        return ['id' => $dispatchId, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function attachDispatchTransfer(int $dispatchLineId, string $transferGroupId): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_consignment_dispatch_lines',
            ['transfer_group_id' => $transferGroupId],
            ['id' => $dispatchLineId, 'transfer_group_id' => null],
            ['%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to attach consignment dispatch transfer.');
        }
    }

    public function finalizeDispatch(int $dispatchId): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_consignment_dispatches', [
            'status' => 'active',
            'posted_at' => current_time('mysql', true),
        ], ['id' => $dispatchId, 'status' => 'posting'], ['%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to finalize consignment dispatch.');
        }
    }

    public function dispatch(int $dispatchId): ?array
    {
        $row = $this->row('rishe_consignment_dispatches', $dispatchId);

        return $row === null ? null : $this->hydrateDispatch($row);
    }

    public function dispatchForUpdate(int $dispatchId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_consignment_dispatches';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $dispatchId
        ), ARRAY_A);

        return is_array($row) ? $this->hydrateDispatch($row, true) : null;
    }

    public function dispatches(array $filters): array
    {
        return array_map([$this, 'formatDispatch'], $this->simpleList(
            'rishe_consignment_dispatches',
            $filters,
            ['account_id', 'status']
        ));
    }

    /** @return array<string, mixed> */
    private function hydrateDispatch(array $row, bool $forUpdate = false): array
    {
        global $wpdb;

        $dispatch = $this->formatDispatch($row);
        $linesTable = $wpdb->prefix . 'rishe_consignment_dispatch_lines';
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE dispatch_id = %d ORDER BY id{$suffix}",
            $dispatch['id']
        ), ARRAY_A);
        $dispatch['lines'] = is_array($lines) ? array_map([$this, 'formatDispatchLine'], $lines) : [];

        return $dispatch;
    }
}
