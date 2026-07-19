<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class ProtectOperationsLedger implements Migration
{
    public function id(): string
    {
        return '2026071922_protect_operations_ledger';
    }

    public function up(): void
    {
        global $wpdb;

        $jobs = $wpdb->prefix . 'rishe_operation_jobs';
        $events = $wpdb->prefix . 'rishe_operation_job_events';
        $incidents = $wpdb->prefix . 'rishe_system_incidents';
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', $wpdb->prefix);
        $triggers = [
            "{$prefix}rishe_operations_job_guard_update" => "
                BEFORE UPDATE ON {$jobs} FOR EACH ROW
                BEGIN
                    IF NEW.job_type <> OLD.job_type OR NEW.aggregate_type <> OLD.aggregate_type OR
                       NEW.aggregate_id <> OLD.aggregate_id OR NEW.idempotency_key <> OLD.idempotency_key OR
                       NEW.request_hash <> OLD.request_hash OR NEW.payload_json <> OLD.payload_json OR
                       NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation job identity is immutable';
                    END IF;
                    IF NEW.attempts < OLD.attempts OR NEW.attempts > NEW.max_attempts THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation job attempts are invalid';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'pending' AND NEW.status IN ('running', 'cancelled')) OR
                        (OLD.status = 'running' AND NEW.status IN ('completed', 'retry_wait', 'failed')) OR
                        (OLD.status = 'retry_wait' AND NEW.status IN ('running', 'pending', 'cancelled')) OR
                        (OLD.status = 'failed' AND NEW.status IN ('pending', 'cancelled'))
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid operation job status transition';
                    END IF;
                    IF NEW.status = 'completed' AND NEW.result_json IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed operation job requires a result';
                    END IF;
                END",
            "{$prefix}rishe_operations_job_guard_delete" => "
                BEFORE DELETE ON {$jobs} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation jobs cannot be deleted';
                END",
            "{$prefix}rishe_operations_event_guard_update" => "
                BEFORE UPDATE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation job events are append-only';
                END",
            "{$prefix}rishe_operations_event_guard_delete" => "
                BEFORE DELETE ON {$events} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation job events are append-only';
                END",
            "{$prefix}rishe_operations_incident_guard_update" => "
                BEFORE UPDATE ON {$incidents} FOR EACH ROW
                BEGIN
                    IF NEW.fingerprint <> OLD.fingerprint OR NEW.source <> OLD.source OR NEW.code <> OLD.code OR
                       NEW.first_seen_at <> OLD.first_seen_at OR NEW.created_at <> OLD.created_at THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incident identity is immutable';
                    END IF;
                    IF NEW.occurrences < OLD.occurrences THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incident occurrences cannot decrease';
                    END IF;
                    IF NOT (
                        NEW.status = OLD.status OR
                        (OLD.status = 'open' AND NEW.status IN ('acknowledged', 'resolved')) OR
                        (OLD.status = 'acknowledged' AND NEW.status IN ('open', 'resolved')) OR
                        (OLD.status = 'resolved' AND NEW.status = 'open')
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid incident status transition';
                    END IF;
                END",
            "{$prefix}rishe_operations_incident_guard_delete" => "
                BEFORE DELETE ON {$incidents} FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'System incidents cannot be deleted';
                END",
        ];

        foreach ($triggers as $name => $body) {
            $wpdb->query("DROP TRIGGER IF EXISTS {$name}");
            if ($wpdb->query("CREATE TRIGGER {$name} {$body}") === false) {
                throw new RuntimeException('Unable to create operations trigger ' . $name . ': ' . $wpdb->last_error);
            }
        }
    }
}
