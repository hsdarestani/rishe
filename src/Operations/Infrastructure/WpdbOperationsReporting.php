<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

trait WpdbOperationsReporting
{
    public function metrics(): array
    {
        global $wpdb;

        $jobs = $wpdb->prefix . 'rishe_operation_jobs';
        $incidents = $wpdb->prefix . 'rishe_system_incidents';
        $outbox = $wpdb->prefix . 'rishe_outbox';
        $tax = $wpdb->prefix . 'rishe_tax_invoices';
        $shipments = $wpdb->prefix . 'rishe_shipments';

        return [
            'jobs_pending' => $this->count($jobs, "status IN ('pending', 'retry_wait')"),
            'jobs_running' => $this->count($jobs, "status = 'running'"),
            'jobs_failed' => $this->count($jobs, "status = 'failed'"),
            'jobs_stale' => $this->count($jobs, "status = 'running' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE"),
            'incidents_open' => $this->count($incidents, "status <> 'resolved'"),
            'incidents_critical' => $this->count($incidents, "status <> 'resolved' AND severity = 'critical'"),
            'outbox_pending' => $this->tableExists($outbox) ? $this->count($outbox, "status = 'pending'") : 0,
            'outbox_failed' => $this->tableExists($outbox) ? $this->count($outbox, "status = 'failed'") : 0,
            'tax_rejected' => $this->tableExists($tax) ? $this->count($tax, "status = 'rejected'") : 0,
            'shipment_exceptions' => $this->tableExists($shipments) ? $this->count($shipments, "status = 'exception'") : 0,
        ];
    }

    public function recentAudit(int $limit = 25): array
    {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $table = $wpdb->prefix . 'rishe_audit_log';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_id, event_type, aggregate_type, aggregate_id, actor_user_id,
                    correlation_id, created_at
             FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['actor_user_id'] = $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'];
        }
        unset($row);

        return $rows;
    }
}
