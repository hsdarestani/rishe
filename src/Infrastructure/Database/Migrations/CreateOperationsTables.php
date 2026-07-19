<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateOperationsTables implements Migration
{
    public function id(): string
    {
        return '2026071921_create_operations_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $jobs = $wpdb->prefix . 'rishe_operation_jobs';
        $events = $wpdb->prefix . 'rishe_operation_job_events';
        $incidents = $wpdb->prefix . 'rishe_system_incidents';

        dbDelta("CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(36) NOT NULL,
            job_type varchar(100) NOT NULL,
            aggregate_type varchar(100) NOT NULL,
            aggregate_id varchar(191) NOT NULL,
            idempotency_key varchar(191) NOT NULL,
            request_hash char(64) NOT NULL,
            payload_json longtext NOT NULL,
            result_json longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            priority smallint(5) unsigned NOT NULL DEFAULT 10,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
            scheduled_at datetime NOT NULL,
            next_retry_at datetime NULL,
            locked_at datetime NULL,
            lock_token char(32) NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            last_error text NULL,
            correlation_id varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY execution_queue (status, scheduled_at, priority, id),
            KEY aggregate_ref (aggregate_type, aggregate_id),
            KEY job_type_status (job_type, status, id),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            job_id bigint(20) unsigned NOT NULL,
            event_type varchar(50) NOT NULL,
            status_from varchar(30) NULL,
            status_to varchar(30) NULL,
            message varchar(2000) NULL,
            context_json longtext NULL,
            actor_user_id bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY job_created (job_id, created_at, id),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$incidents} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            incident_id char(36) NOT NULL,
            fingerprint char(64) NOT NULL,
            severity varchar(20) NOT NULL,
            source varchar(100) NOT NULL,
            code varchar(100) NOT NULL,
            message varchar(2000) NOT NULL,
            context_json longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'open',
            occurrences bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            acknowledged_by bigint(20) unsigned NULL,
            acknowledged_at datetime NULL,
            resolved_by bigint(20) unsigned NULL,
            resolved_at datetime NULL,
            correlation_id varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY incident_id (incident_id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY status_severity (status, severity, last_seen_at),
            KEY source_code (source, code),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        foreach ([$jobs, $events, $incidents] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create operations table: ' . $table);
            }
        }
    }
}
