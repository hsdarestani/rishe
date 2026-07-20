<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database\Migrations;

use Rishe\Infrastructure\Database\Migration;
use RuntimeException;

final class CreateAnalyticsTables implements Migration
{
    public function id(): string
    {
        return '2026071923_create_analytics_tables';
    }

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sources = $wpdb->prefix . 'rishe_analytics_sources';
        $campaigns = $wpdb->prefix . 'rishe_analytics_campaigns';
        $attribution = $wpdb->prefix . 'rishe_order_attribution';
        $prices = $wpdb->prefix . 'rishe_price_history';
        $targets = $wpdb->prefix . 'rishe_analytics_targets';
        $events = $wpdb->prefix . 'rishe_business_events';
        $projection = $wpdb->prefix . 'rishe_analytics_projection_state';
        $facts = $wpdb->prefix . 'rishe_analytics_facts_daily';
        $customers = $wpdb->prefix . 'rishe_analytics_dim_customers';
        $products = $wpdb->prefix . 'rishe_analytics_dim_products';
        $orders = $wpdb->prefix . 'rishe_analytics_dim_orders';
        $time = $wpdb->prefix . 'rishe_analytics_dim_time';
        $snapshots = $wpdb->prefix . 'rishe_inventory_daily_snapshots';
        $alerts = $wpdb->prefix . 'rishe_analytics_alerts';

        dbDelta("CREATE TABLE {$sources} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(60) NOT NULL,
            name varchar(191) NOT NULL,
            channel varchar(60) NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY active_channel (is_active, channel)
        ) {$charset};");

        dbDelta("CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_key char(36) NOT NULL,
            name varchar(191) NOT NULL,
            channel varchar(60) NULL,
            source_id bigint(20) unsigned NULL,
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            objective varchar(500) NULL,
            target_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            budget_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'planned',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_key (campaign_key),
            KEY campaign_window (status, starts_at, ends_at),
            KEY source_id (source_id),
            KEY channel (channel)
        ) {$charset};");

        dbDelta("CREATE TABLE {$attribution} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NULL,
            campaign_id bigint(20) unsigned NULL,
            branch_id bigint(20) unsigned NULL,
            salesperson_user_id bigint(20) unsigned NULL,
            province varchar(100) NULL,
            city varchar(100) NULL,
            attributed_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY source_id (source_id),
            KEY campaign_id (campaign_id),
            KEY branch_id (branch_id),
            KEY salesperson_user_id (salesperson_user_id),
            KEY geography (province, city)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            price_key char(36) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            channel varchar(60) NOT NULL,
            purchase_price_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            cogs_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            selling_price_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            effective_from datetime NOT NULL,
            effective_to datetime NULL,
            reason varchar(500) NULL,
            actor_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY price_key (price_key),
            UNIQUE KEY product_channel_from (product_id, channel, effective_from),
            KEY active_price (product_id, channel, effective_to),
            KEY effective_window (effective_from, effective_to)
        ) {$charset};");

        dbDelta("CREATE TABLE {$targets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            target_key char(36) NOT NULL,
            dimension_hash char(64) NOT NULL,
            kpi varchar(30) NOT NULL,
            period_type varchar(20) NOT NULL,
            starts_on date NOT NULL,
            ends_on date NOT NULL,
            product_line varchar(100) NULL,
            sales_channel varchar(60) NULL,
            province varchar(100) NULL,
            city varchar(100) NULL,
            target_value bigint(20) unsigned NOT NULL,
            is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY target_key (target_key),
            UNIQUE KEY dimension_hash (dimension_hash),
            KEY active_window (is_active, starts_on, ends_on),
            KEY kpi_period (kpi, period_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key char(36) NOT NULL,
            event_group_key char(36) NULL,
            source_audit_event_id char(36) NULL,
            event_sequence int(10) unsigned NOT NULL DEFAULT 0,
            event_type varchar(60) NOT NULL,
            occurred_at datetime NOT NULL,
            actor_user_id bigint(20) unsigned NULL,
            branch_id bigint(20) unsigned NULL,
            sales_channel varchar(60) NULL,
            source_code varchar(60) NULL,
            campaign_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NULL,
            order_id bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NULL,
            product_line varchar(100) NULL,
            quantity_scaled bigint(20) NOT NULL DEFAULT 0,
            revenue_irr bigint(20) NOT NULL DEFAULT 0,
            cogs_irr bigint(20) NOT NULL DEFAULT 0,
            gross_profit_irr bigint(20) NOT NULL DEFAULT 0,
            discount_irr bigint(20) NOT NULL DEFAULT 0,
            order_count bigint(20) NOT NULL DEFAULT 0,
            province varchar(100) NULL,
            city varchar(100) NULL,
            aggregate_type varchar(60) NULL,
            aggregate_id varchar(191) NULL,
            correlation_id varchar(64) NULL,
            payload_json longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            UNIQUE KEY audit_sequence (source_audit_event_id, event_sequence),
            KEY event_time (event_type, occurred_at),
            KEY order_event (order_id, event_type, occurred_at),
            KEY customer_time (customer_id, occurred_at),
            KEY product_time (product_id, occurred_at),
            KEY campaign_time (campaign_id, occurred_at),
            KEY channel_time (sales_channel, occurred_at),
            KEY source_time (source_code, occurred_at),
            KEY geography_time (province, city, occurred_at),
            KEY correlation_id (correlation_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$projection} (
            projection_name varchar(100) NOT NULL,
            last_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (projection_name)
        ) {$charset};");

        dbDelta("CREATE TABLE {$facts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fact_date date NOT NULL,
            dimension_hash char(64) NOT NULL,
            event_type varchar(60) NOT NULL,
            branch_id bigint(20) unsigned NULL,
            sales_channel varchar(60) NULL,
            source_code varchar(60) NULL,
            campaign_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NULL,
            order_id bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NULL,
            product_line varchar(100) NULL,
            province varchar(100) NULL,
            city varchar(100) NULL,
            sales_qty_scaled bigint(20) NOT NULL DEFAULT 0,
            revenue_irr bigint(20) NOT NULL DEFAULT 0,
            cogs_irr bigint(20) NOT NULL DEFAULT 0,
            gross_profit_irr bigint(20) NOT NULL DEFAULT 0,
            discount_irr bigint(20) NOT NULL DEFAULT 0,
            orders_count bigint(20) NOT NULL DEFAULT 0,
            events_count bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY date_dimension (fact_date, dimension_hash),
            KEY date_channel (fact_date, sales_channel),
            KEY date_product_line (fact_date, product_line),
            KEY date_campaign (fact_date, campaign_id),
            KEY date_geography (fact_date, province, city),
            KEY customer_date (customer_id, fact_date),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY event_type (event_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$customers} (
            customer_id bigint(20) unsigned NOT NULL,
            province varchar(100) NULL,
            city varchar(100) NULL,
            register_date date NULL,
            first_purchase date NULL,
            last_purchase date NULL,
            source_code varchar(60) NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (customer_id),
            KEY geography (province, city),
            KEY purchase_dates (first_purchase, last_purchase),
            KEY source_code (source_code)
        ) {$charset};");

        dbDelta("CREATE TABLE {$products} (
            product_id bigint(20) unsigned NOT NULL,
            sku varchar(100) NOT NULL,
            product_name varchar(191) NOT NULL,
            product_line varchar(100) NULL,
            category varchar(100) NULL,
            supplier_id bigint(20) unsigned NULL,
            latest_batch_code varchar(100) NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (product_id),
            UNIQUE KEY sku (sku),
            KEY product_line (product_line),
            KEY category (category),
            KEY supplier_id (supplier_id),
            KEY latest_batch_code (latest_batch_code)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            order_id bigint(20) unsigned NOT NULL,
            sales_channel varchar(60) NOT NULL,
            source_code varchar(60) NULL,
            campaign_id bigint(20) unsigned NULL,
            branch_id bigint(20) unsigned NULL,
            salesperson_user_id bigint(20) unsigned NULL,
            discount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            total_irr bigint(20) unsigned NOT NULL DEFAULT 0,
            cogs_irr bigint(20) unsigned NULL,
            status varchar(30) NOT NULL,
            paid_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            KEY channel (sales_channel),
            KEY source_code (source_code),
            KEY campaign_id (campaign_id),
            KEY branch_id (branch_id),
            KEY salesperson_user_id (salesperson_user_id),
            KEY paid_at (paid_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$time} (
            date_key date NOT NULL,
            day_of_week tinyint(3) unsigned NOT NULL,
            week_of_year tinyint(3) unsigned NOT NULL,
            month_number tinyint(3) unsigned NOT NULL,
            quarter_number tinyint(3) unsigned NOT NULL,
            year_number smallint(5) unsigned NOT NULL,
            month_key char(7) NOT NULL,
            PRIMARY KEY  (date_key),
            KEY year_month (year_number, month_number),
            KEY quarter (year_number, quarter_number),
            KEY month_key (month_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$snapshots} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_date date NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            warehouse_id bigint(20) unsigned NOT NULL,
            sku varchar(100) NOT NULL,
            product_line varchar(100) NULL,
            inventory_scaled bigint(20) NOT NULL DEFAULT 0,
            sales_qty_scaled bigint(20) NOT NULL DEFAULT 0,
            revenue_irr bigint(20) NOT NULL DEFAULT 0,
            cogs_irr bigint(20) NOT NULL DEFAULT 0,
            gross_profit_irr bigint(20) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY date_product_warehouse (snapshot_date, product_id, warehouse_id),
            KEY date_product_line (snapshot_date, product_line),
            KEY product_id (product_id),
            KEY warehouse_id (warehouse_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$alerts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            alert_key char(36) NOT NULL,
            fingerprint char(64) NOT NULL,
            rule_code varchar(60) NOT NULL,
            severity varchar(20) NOT NULL,
            title varchar(191) NOT NULL,
            description text NOT NULL,
            related_report varchar(500) NULL,
            entity_type varchar(60) NULL,
            entity_id varchar(191) NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            detected_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
            acknowledged_at datetime NULL,
            resolved_at datetime NULL,
            updated_by bigint(20) unsigned NULL,
            payload_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY alert_key (alert_key),
            UNIQUE KEY fingerprint (fingerprint),
            KEY status_severity (status, severity),
            KEY rule_code (rule_code),
            KEY last_seen_at (last_seen_at),
            KEY entity_lookup (entity_type, entity_id)
        ) {$charset};");

        foreach (
            [$sources, $campaigns, $attribution, $prices, $targets, $events, $projection, $facts,
            $customers, $products, $orders, $time, $snapshots, $alerts] as $table
        ) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException('Unable to create required analytics table: ' . $table);
            }
        }

        $this->seedSources($sources);
    }

    private function seedSources(string $table): void
    {
        global $wpdb;
        $sources = [
            'website' => ['Website', 'website'],
            'instagram' => ['Instagram', 'instagram'],
            'telegram' => ['Telegram', 'telegram'],
            'sms' => ['SMS', 'sms'],
            'digikala' => ['Digikala', 'marketplace'],
            'basalam' => ['Basalam', 'marketplace'],
            'snapp_shop' => ['Snapp Shop', 'marketplace'],
            'snapp_pay' => ['Snapp Pay', 'payment'],
            'pos' => ['In-person / POS', 'pos'],
            'phone' => ['Phone', 'manual'],
            'referral' => ['Referral', 'referral'],
            'direct' => ['Direct', 'direct'],
        ];
        foreach ($sources as $code => [$name, $channel]) {
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                 (code, name, channel, is_active, created_by, created_at, updated_at)
                 VALUES (%s, %s, %s, 1, 1, %s, %s)",
                $code,
                $name,
                $channel,
                current_time('mysql', true),
                current_time('mysql', true)
            );
            if ($wpdb->query($sql) === false) {
                throw new RuntimeException('Unable to seed analytics source: ' . $code);
            }
        }
    }
}
