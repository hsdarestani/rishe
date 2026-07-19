<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait WpdbB2BReturnStorage
{
    public function createReturn(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_consignment_returns';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['payload_hash'] !== (string) $data['payload_hash']) {
                throw new B2BDomainException('Return idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true, 'line_ids' => []];
        }

        $now = current_time('mysql', true);
        $returnId = $this->insert('rishe_consignment_returns', [
            'public_id' => wp_generate_uuid4(),
            'fiscal_year' => $data['fiscal_year'],
            'document_number' => $data['document_number'],
            'dispatch_id' => $data['dispatch_id'],
            'account_id' => $data['account_id'],
            'source_warehouse_id' => $data['source_warehouse_id'],
            'destination_warehouse_id' => $data['destination_warehouse_id'],
            'status' => 'posting',
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'notes' => $data['notes'],
            'correlation_id' => $data['correlation_id'],
            'returned_by' => $data['actor_user_id'],
            'returned_at' => $data['returned_at'],
            'created_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s',
        ], 'consignment return');

        $lineIds = [];
        foreach ($data['lines'] as $line) {
            $lineIds[] = $this->insert('rishe_consignment_return_lines', [
                'return_id' => $returnId,
                'dispatch_line_id' => $line['dispatch_line_id'],
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'quantity_scaled' => $line['quantity_scaled'],
                'transfer_group_id' => null,
                'created_at' => $now,
            ], ['%d', '%d', '%d', '%s', '%d', '%s', '%s'], 'consignment return line');
        }

        return ['id' => $returnId, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function attachReturnTransfer(int $returnLineId, string $transferGroupId): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_consignment_return_lines',
            ['transfer_group_id' => $transferGroupId],
            ['id' => $returnLineId, 'transfer_group_id' => null],
            ['%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to attach consignment return transfer.');
        }
    }

    public function finalizeReturn(int $returnId, int $dispatchId, array $lineUpdates, string $dispatchStatus): void
    {
        global $wpdb;

        $linesTable = $wpdb->prefix . 'rishe_consignment_dispatch_lines';
        foreach ($lineUpdates as $update) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$linesTable}
                 SET returned_quantity_scaled = returned_quantity_scaled + %d
                 WHERE id = %d
                   AND sold_quantity_scaled + returned_quantity_scaled + %d <= quantity_scaled",
                $update['quantity_scaled'],
                $update['dispatch_line_id'],
                $update['quantity_scaled']
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to update consignment returned quantity.');
            }
        }
        $updated = $wpdb->update($wpdb->prefix . 'rishe_consignment_returns', [
            'status' => 'posted',
            'posted_at' => current_time('mysql', true),
        ], ['id' => $returnId, 'status' => 'posting'], ['%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to finalize consignment return.');
        }
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_consignment_dispatches',
            ['status' => $dispatchStatus],
            ['id' => $dispatchId],
            ['%s'],
            ['%d']
        );
        if ($updated === false) {
            throw new RuntimeException('Unable to update consignment dispatch status.');
        }
    }

    public function returnDocument(int $returnId): ?array
    {
        global $wpdb;

        $document = $this->row('rishe_consignment_returns', $returnId);
        if ($document === null) {
            return null;
        }
        $linesTable = $wpdb->prefix . 'rishe_consignment_return_lines';
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$linesTable} WHERE return_id = %d ORDER BY id",
            $returnId
        ), ARRAY_A);
        $document = $this->formatReturn($document);
        $document['lines'] = is_array($lines) ? array_map([$this, 'formatReturnLine'], $lines) : [];

        return $document;
    }
}
