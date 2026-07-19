<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use RuntimeException;

trait WpdbTaxSubmissionStorage
{
    public function recordSubmission(int $invoiceId, array $data): int
    {
        return $this->insert('rishe_tax_submissions', [
            'public_id' => wp_generate_uuid4(),
            'tax_invoice_id' => $invoiceId,
            'attempt_number' => $data['attempt_number'],
            'request_hash' => $data['request_hash'],
            'response_hash' => $data['response_hash'],
            'reference_number' => $data['reference_number'],
            'remote_uid' => $data['uid'],
            'status' => $data['status'],
            'error_code' => $data['error_code'],
            'error_message' => $data['error_message'],
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], [], 'tax submission');
    }

    public function recordStatus(int $invoiceId, array $data): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_status_events';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE tax_invoice_id = %d AND payload_hash = %s",
            $invoiceId,
            $data['payload_hash']
        ));
        if ($existing !== null) {
            return (int) $existing;
        }

        return $this->insert('rishe_tax_status_events', [
            'tax_invoice_id' => $invoiceId,
            'status' => $data['status'],
            'source' => $data['source'],
            'reference_number' => $data['reference_number'],
            'payload_hash' => $data['payload_hash'],
            'message' => $data['message'],
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], [], 'tax status event');
    }

    public function updateInvoiceStatus(
        int $invoiceId,
        string $status,
        ?string $referenceNumber,
        ?string $uid,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        global $wpdb;

        $now = current_time('mysql', true);
        $data = [
            'status' => $status,
            'reference_number' => $referenceNumber,
            'remote_uid' => $uid,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
            'submission_attempts' => $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rishe_tax_submissions WHERE tax_invoice_id = %d',
                $invoiceId
            )),
            'updated_at' => $now,
        ];
        if ($status === 'submitted') {
            $data['submitted_at'] = $now;
        } elseif ($status === 'accepted') {
            $data['accepted_at'] = $now;
        } elseif ($status === 'rejected') {
            $data['rejected_at'] = $now;
        }
        if ($wpdb->update($wpdb->prefix . 'rishe_tax_invoices', $data, ['id' => $invoiceId]) === false) {
            throw new RuntimeException('Unable to update tax invoice status: ' . $wpdb->last_error);
        }
    }

    public function markSourceDerived(int $sourceInvoiceId, string $status, int $derivedInvoiceId): void
    {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'rishe_tax_invoices', [
            'status' => $status,
            'derived_invoice_id' => $derivedInvoiceId,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $sourceInvoiceId]);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to mark source tax invoice as derived.');
        }
    }
}
