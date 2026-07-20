<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure;

use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;
use RuntimeException;

trait WpdbAnalyticsReporting
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function executiveDashboard(array $filters): array
    {
        global $wpdb;
        $facts = $this->table('analytics_facts_daily');
        [$where, $args] = $this->factFilter($filters);
        $summary = $wpdb->get_row($this->prepare(
            "SELECT COALESCE(SUM(revenue_irr),0) AS revenue_irr,
                    COALESCE(SUM(cogs_irr),0) AS cogs_irr,
                    COALESCE(SUM(gross_profit_irr),0) AS gross_profit_irr,
                    COALESCE(SUM(discount_irr),0) AS discount_irr,
                    COALESCE(SUM(orders_count),0) AS order_count,
                    COALESCE(SUM(sales_qty_scaled),0) AS sales_qty_scaled
             FROM {$facts} f WHERE {$where}",
            $args
        ), ARRAY_A) ?: [];
        $orderCount = max(0, (int) ($summary['order_count'] ?? 0));
        $summary = $this->normalizeRow($summary);
        $summary['average_order_value_irr'] = $orderCount > 0
            ? intdiv((int) $summary['revenue_irr'], $orderCount)
            : 0;
        $today = gmdate('Y-m-d');
        $weekStart = gmdate('Y-m-d', strtotime('monday this week'));
        $monthStart = gmdate('Y-m-01');
        $summary['today_revenue_irr'] = $this->periodRevenue($today, $today, $filters);
        $summary['week_revenue_irr'] = $this->periodRevenue($weekStart, $today, $filters);
        $summary['month_revenue_irr'] = $this->periodRevenue($monthStart, $today, $filters);
        $alerts = $this->table('analytics_alerts');
        $summary['open_alerts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$alerts} WHERE status IN ('open','acknowledged')");

        return $summary;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function salesDashboard(array $filters): array
    {
        global $wpdb;
        $facts = $this->table('analytics_facts_daily');
        [$where, $args] = $this->factFilter($filters);
        $summary = $this->executiveDashboard($filters);
        $channels = $wpdb->get_results($this->prepare(
            "SELECT COALESCE(sales_channel, 'unknown') AS label,
                    SUM(revenue_irr) AS revenue_irr, SUM(gross_profit_irr) AS gross_profit_irr,
                    SUM(orders_count) AS order_count
             FROM {$facts} f WHERE {$where}
             GROUP BY sales_channel ORDER BY revenue_irr DESC LIMIT 50",
            $args
        ), ARRAY_A);
        $productLines = $wpdb->get_results($this->prepare(
            "SELECT COALESCE(product_line, 'unclassified') AS label,
                    SUM(revenue_irr) AS revenue_irr, SUM(gross_profit_irr) AS gross_profit_irr,
                    SUM(sales_qty_scaled) AS sales_qty_scaled
             FROM {$facts} f WHERE {$where}
             GROUP BY product_line ORDER BY revenue_irr DESC LIMIT 100",
            $args
        ), ARRAY_A);
        $trend = $wpdb->get_results($this->prepare(
            "SELECT fact_date, SUM(revenue_irr) AS revenue_irr, SUM(gross_profit_irr) AS gross_profit_irr,
                    SUM(orders_count) AS order_count
             FROM {$facts} f WHERE {$where}
             GROUP BY fact_date ORDER BY fact_date ASC",
            $args
        ), ARRAY_A);

        return $summary + [
            'by_channel' => $this->normalizeRows($channels),
            'by_product_line' => $this->normalizeRows($productLines),
            'trend' => $this->normalizeRows($trend),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function inventoryDashboard(array $filters): array
    {
        global $wpdb;
        $snapshots = $this->table('inventory_daily_snapshots');
        $date = (string) ($filters['to'] ?? gmdate('Y-m-d'));
        $latest = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(snapshot_date) FROM {$snapshots} WHERE snapshot_date <= %s",
            $date
        ));
        if (!$latest) {
            return [
                'snapshot_date' => null,
                'inventory_scaled' => 0,
                'low_stock_count' => 0,
                'stagnant_count' => 0,
                'turnover_basis_points' => 0,
                'rows' => [],
            ];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, sku, product_line, SUM(inventory_scaled) AS inventory_scaled,
                    SUM(sales_qty_scaled) AS sales_qty_scaled, SUM(revenue_irr) AS revenue_irr,
                    SUM(cogs_irr) AS cogs_irr, SUM(gross_profit_irr) AS gross_profit_irr
             FROM {$snapshots} WHERE snapshot_date = %s
             GROUP BY product_id, sku, product_line ORDER BY inventory_scaled DESC",
            $latest
        ), ARRAY_A);
        $minimums = function_exists('get_option') ? get_option('rishe_analytics_min_stock', []) : [];
        $low = 0;
        $stagnant = 0;
        $inventory = 0;
        $sales = 0;
        $normalized = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = $this->normalizeRow($row);
            $threshold = is_array($minimums)
                ? (int) ($minimums[(string) $row['product_id']] ?? $minimums[$row['product_id']] ?? 0)
                : 0;
            $row['minimum_stock_scaled'] = $threshold;
            $row['is_low_stock'] = $threshold > 0 && (int) $row['inventory_scaled'] < $threshold;
            $row['is_stagnant'] = (int) $row['inventory_scaled'] > 0 && (int) $row['sales_qty_scaled'] === 0;
            $low += $row['is_low_stock'] ? 1 : 0;
            $stagnant += $row['is_stagnant'] ? 1 : 0;
            $inventory += (int) $row['inventory_scaled'];
            $sales += (int) $row['sales_qty_scaled'];
            $normalized[] = $row;
        }

        return [
            'snapshot_date' => $latest,
            'inventory_scaled' => $inventory,
            'sales_qty_scaled' => $sales,
            'low_stock_count' => $low,
            'stagnant_count' => $stagnant,
            'turnover_basis_points' => $inventory > 0 ? intdiv($sales * 10000, $inventory) : 0,
            'rows' => $normalized,
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function financeDashboard(array $filters): array
    {
        global $wpdb;
        $facts = $this->table('analytics_facts_daily');
        [$where, $args] = $this->factFilter($filters);
        $summary = $wpdb->get_row($this->prepare(
            "SELECT COALESCE(SUM(revenue_irr),0) AS revenue_irr,
                    COALESCE(SUM(cogs_irr),0) AS cogs_irr,
                    COALESCE(SUM(gross_profit_irr),0) AS gross_profit_irr,
                    COALESCE(SUM(discount_irr),0) AS discount_irr
             FROM {$facts} f WHERE {$where}",
            $args
        ), ARRAY_A) ?: [];
        $trend = $wpdb->get_results($this->prepare(
            "SELECT fact_date, SUM(revenue_irr) AS revenue_irr, SUM(cogs_irr) AS cogs_irr,
                    SUM(gross_profit_irr) AS gross_profit_irr
             FROM {$facts} f WHERE {$where}
             GROUP BY fact_date ORDER BY fact_date ASC",
            $args
        ), ARRAY_A);

        return $this->normalizeRow($summary) + ['trend' => $this->normalizeRows($trend)];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function customerDashboard(array $filters): array
    {
        global $wpdb;
        $dimensions = $this->table('analytics_dim_customers');
        $facts = $this->table('analytics_facts_daily');
        [$where, $args] = $this->factFilter($filters);
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $newCustomers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$dimensions} WHERE register_date BETWEEN %s AND %s",
            $from,
            $to
        ));
        $activeCustomers = (int) $wpdb->get_var($this->prepare(
            "SELECT COUNT(DISTINCT customer_id) FROM {$facts} f WHERE {$where} AND customer_id IS NOT NULL AND event_type = 'order_paid'",
            $args
        ));
        $repeatCustomers = (int) $wpdb->get_var($this->prepare(
            "SELECT COUNT(*) FROM (
                SELECT customer_id FROM {$facts} f WHERE {$where} AND customer_id IS NOT NULL AND event_type = 'order_paid'
                GROUP BY customer_id HAVING SUM(orders_count) > 1
             ) repeaters",
            $args
        ));
        $summary = $this->executiveDashboard($filters);
        $frequencyBasisPoints = $activeCustomers > 0
            ? intdiv((int) $summary['order_count'] * 10000, $activeCustomers)
            : 0;

        return [
            'new_customers' => $newCustomers,
            'active_customers' => $activeCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_rate_basis_points' => $activeCustomers > 0 ? intdiv($repeatCustomers * 10000, $activeCustomers) : 0,
            'average_order_value_irr' => (int) $summary['average_order_value_irr'],
            'purchase_frequency_basis_points' => $frequencyBasisPoints,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function alertCandidates(string $now): array
    {
        global $wpdb;
        $today = substr($now, 0, 10);
        $candidates = [];
        $facts = $this->table('analytics_facts_daily');
        $loss = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS occurrences, SUM(ABS(gross_profit_irr)) AS loss_irr
             FROM {$facts} WHERE fact_date = %s AND event_type = 'order_paid' AND gross_profit_irr < 0",
            $today
        ), ARRAY_A) ?: [];
        if ((int) ($loss['occurrences'] ?? 0) > 0) {
            $candidates[] = $this->alertCandidate(
                'sale_below_cogs',
                'critical',
                'Sales below cost detected',
                sprintf('%d sale fact rows have negative gross profit today.', (int) $loss['occurrences']),
                '/wp-admin/admin.php?page=rishe-analytics&report=finance',
                'analytics_day',
                $today,
                ['loss_irr' => (int) ($loss['loss_irr'] ?? 0)]
            );
        }
        $orders = $this->table('sales_orders');
        $ageMinutes = max(5, (int) (function_exists('get_option') ? get_option('rishe_unpaid_alert_minutes', 30) : 30));
        $unpaid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders}
             WHERE payment_status = 'unpaid' AND status = 'pending_payment'
               AND created_at <= DATE_SUB(%s, INTERVAL %d MINUTE)",
            $now,
            $ageMinutes
        ));
        if ($unpaid > 0) {
            $candidates[] = $this->alertCandidate(
                'unpaid_orders',
                'warning',
                'Unpaid orders require attention',
                sprintf('%d orders have remained unpaid for more than %d minutes.', $unpaid, $ageMinutes),
                '/wp-admin/admin.php?page=rishe-analytics&report=sales',
                'sales_orders',
                $today,
                ['count' => $unpaid, 'age_minutes' => $ageMinutes]
            );
        }
        $targets = $this->targets(['active_on' => $today]);
        foreach ($targets as $target) {
            if ((int) $target['actual_value'] >= (int) $target['target_value']) {
                continue;
            }
            $candidates[] = $this->alertCandidate(
                'target_below_plan',
                'warning',
                'KPI is below target',
                sprintf('%s actual is %d against a target of %d.', (string) $target['kpi'], (int) $target['actual_value'], (int) $target['target_value']),
                '/wp-admin/admin.php?page=rishe-analytics&report=executive',
                'analytics_target',
                (string) $target['id'],
                ['target_id' => (int) $target['id'], 'actual' => (int) $target['actual_value'], 'target' => (int) $target['target_value']]
            );
        }
        $minimums = function_exists('get_option') ? get_option('rishe_analytics_min_stock', []) : [];
        if (is_array($minimums) && $minimums !== []) {
            $batches = $this->table('inventory_batches');
            foreach ($minimums as $productId => $minimum) {
                $productId = (int) $productId;
                $minimum = (int) $minimum;
                if ($productId <= 0 || $minimum <= 0) {
                    continue;
                }
                $available = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(quantity_on_hand - quantity_reserved),0)
                     FROM {$batches} WHERE product_id = %d AND status = 'active'",
                    $productId
                ));
                if ($available >= $minimum) {
                    continue;
                }
                $candidates[] = $this->alertCandidate(
                    'low_inventory',
                    $available <= 0 ? 'critical' : 'warning',
                    'Inventory below minimum',
                    sprintf('Product %d has %d available against a minimum of %d.', $productId, $available, $minimum),
                    '/wp-admin/admin.php?page=rishe-analytics&report=inventory',
                    'product',
                    (string) $productId,
                    ['available_scaled' => $available, 'minimum_scaled' => $minimum]
                );
            }
        }
        $events = $this->table('business_events');
        $returns = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM {$events} WHERE DATE(occurred_at) = %s AND event_type = 'order_returned'",
            $today
        ));
        $paid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM {$events} WHERE DATE(occurred_at) = %s AND event_type = 'order_paid'",
            $today
        ));
        $returnThresholdBps = max(1, (int) (function_exists('get_option') ? get_option('rishe_return_alert_basis_points', 1000) : 1000));
        if ($paid > 0 && intdiv($returns * 10000, $paid) >= $returnThresholdBps) {
            $candidates[] = $this->alertCandidate(
                'return_spike',
                'warning',
                'Return rate is unusually high',
                sprintf('%d returned orders versus %d paid orders today.', $returns, $paid),
                '/wp-admin/admin.php?page=rishe-analytics&report=sales',
                'analytics_day',
                $today,
                ['returns' => $returns, 'paid' => $paid, 'threshold_basis_points' => $returnThresholdBps]
            );
        }

        return $candidates;
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    public function upsertAlert(array $candidate): array
    {
        global $wpdb;
        $table = $this->table('analytics_alerts');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE fingerprint = %s",
            $candidate['fingerprint']
        ), ARRAY_A);
        if (is_array($existing)) {
            $updated = $wpdb->update($table, [
                'severity' => $candidate['severity'],
                'title' => $candidate['title'],
                'description' => $candidate['description'],
                'related_report' => $candidate['related_report'],
                'last_seen_at' => $this->now(),
                'occurrence_count' => (int) $existing['occurrence_count'] + 1,
                'payload_json' => $this->encode($candidate['payload']),
                'updated_at' => $this->now(),
            ], ['id' => $existing['id']], ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('Unable to update analytics alert.');
            }
            $row = $this->row($table, (int) $existing['id']);
            $row['created'] = false;

            return $row;
        }
        $inserted = $wpdb->insert($table, [
            'alert_key' => $this->uuid(),
            'fingerprint' => $candidate['fingerprint'],
            'rule_code' => $candidate['rule_code'],
            'severity' => $candidate['severity'],
            'title' => $candidate['title'],
            'description' => $candidate['description'],
            'related_report' => $candidate['related_report'],
            'entity_type' => $candidate['entity_type'],
            'entity_id' => $candidate['entity_id'],
            'status' => 'open',
            'detected_at' => $this->now(),
            'last_seen_at' => $this->now(),
            'occurrence_count' => 1,
            'payload_json' => $this->encode($candidate['payload']),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        $this->assertInserted($inserted, 'Unable to create analytics alert');
        $row = $this->row($table, (int) $wpdb->insert_id);
        $row['created'] = true;

        return $row;
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function alerts(array $filters = []): array
    {
        global $wpdb;
        $table = $this->table('analytics_alerts');
        $where = ['1=1'];
        $args = [];
        foreach (['status', 'severity', 'rule_code'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "{$field} = %s";
                $args[] = (string) $filters[$field];
            }
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . '
                ORDER BY FIELD(severity,\'critical\',\'warning\',\'info\'), last_seen_at DESC LIMIT 1000';
        $rows = $wpdb->get_results($this->prepare($sql, $args), ARRAY_A);

        return $this->normalizeRows($rows);
    }

    /** @return array<string, mixed> */
    public function updateAlert(int $alertId, string $status, int $actorUserId): array
    {
        global $wpdb;
        $table = $this->table('analytics_alerts');
        $fields = [
            'status' => $status,
            'updated_at' => $this->now(),
            'updated_by' => $actorUserId,
            'acknowledged_at' => $status === 'acknowledged' ? $this->now() : null,
            'resolved_at' => $status === 'resolved' ? $this->now() : null,
        ];
        $updated = $wpdb->update($table, $fields, ['id' => $alertId], ['%s', '%s', '%d', '%s', '%s'], ['%d']);
        if ($updated === false) {
            throw new RuntimeException('Unable to update analytics alert.');
        }

        return $this->row($table, $alertId);
    }

    /** @param mixed $rows @return list<array<string, mixed>> */
    private function normalizeRows(mixed $rows): array
    {
        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $filters */
    private function periodRevenue(string $from, string $to, array $filters): int
    {
        global $wpdb;
        $facts = $this->table('analytics_facts_daily');
        $periodFilters = $filters;
        $periodFilters['from'] = $from;
        $periodFilters['to'] = $to;
        [$where, $args] = $this->factFilter($periodFilters);

        return (int) $wpdb->get_var($this->prepare("SELECT COALESCE(SUM(revenue_irr),0) FROM {$facts} f WHERE {$where}", $args));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function alertCandidate(
        string $ruleCode,
        string $severity,
        string $title,
        string $description,
        string $report,
        string $entityType,
        string $entityId,
        array $payload
    ): array {
        return [
            'fingerprint' => hash('sha256', implode('|', [$ruleCode, $entityType, $entityId])),
            'rule_code' => $ruleCode,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'related_report' => $report,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
        ];
    }
}
