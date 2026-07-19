<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;

final class CreateFoundationTables implements Migration
{
    public function id(): string
    {
        return '2026071901_create_foundation_tables';
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $audit = $wpdb->prefix . 'rishe_audit_log';
        $idempotency = $wpdb->prefix . 'rishe_idempotency_keys';
        $outbox = $wpdb->prefix . 'rishe_outbox';

        dbDelta("CREATE TABLE {$audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            event_type varchar(191) NOT NULL,
            aggregate_type varchar(100) NOT NULL,
            aggregate_id varchar(191) NOT NULL,
            actor_user_id bigint(20) unsigned NULL,
            correlation_id varchar(64) NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY aggregate (aggregate_type, aggregate_id),
            KEY correlation_id (correlation_id),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$idempotency} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(100) NOT NULL,
            idempotency_key varchar(191) NOT NULL,
            request_hash char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'processing',
            response_code smallint(5) unsigned NULL,
            response_body longtext NULL,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_key (scope, idempotency_key),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$outbox} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            topic varchar(191) NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            processed_at datetime NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY dispatch_queue (status, available_at)
        ) {$charset};");
    }
}
