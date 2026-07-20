<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure;

use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;
use RuntimeException;

trait WpdbAnalyticsMasterData
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createSource(array $data): array
    {
        global $wpdb;
        $table = $this->table('analytics_sources');
        $inserted = $wpdb->insert($table, [
            'code' => $data['code'],
            'name' => $data['name'],
            'channel' => $data['channel'],
            'is_active' => (int) $data['is_active'],
            'created_by' => $data['created_by'],
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        $this->assertInserted($inserted, 'Unable to create analytics source');

        return $this->sourceById((int) $wpdb->insert_id);
    }

    /** @return list<array<string, mixed>> */
    public function sources(bool $activeOnly = false): array
    {
        global $wpdb;
        $table = $this->table('analytics_sources');
        $sql = "SELECT * FROM {$table}" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name ASC, id ASC';
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createCampaign(array $data): array
    {
        global $wpdb;
        if ($data['source_id'] !== null) {
            $source = $this->sourceById((int) $data['source_id']);
            if (!(bool) $source['is_active']) {
                throw new AnalyticsDomainException('Campaign source must be active.');
            }
        }
        $table = $this->table('analytics_campaigns');
        $inserted = $wpdb->insert($table, [
            'campaign_key' => $data['campaign_key'],
            'name' => $data['name'],
            'channel' => $data['channel'],
            'source_id' => $data['source_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'objective' => $data['objective'],
            'target_irr' => $data['target_irr'],
            'budget_irr' => $data['budget_irr'],
            'status' => $data['status'],
            'created_by' => $data['created_by'],
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']);
        $this->assertInserted($inserted, 'Unable to create analytics campaign');

        return $this->campaignById((int) $wpdb->insert_id);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function campaigns(array $filters = []): array
    {
        global $wpdb;
        $table = $this->table('analytics_campaigns');
        $sources = $this->table('analytics_sources');
        $where = ['1=1'];
        $args = [];
        foreach (['status', 'channel'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "c.{$field} = %s";
                $args[] = (string) $filters[$field];
            }
        }
        if (!empty($filters['active_on'])) {
            $where[] = '%s BETWEEN DATE(c.starts_at) AND DATE(c.ends_at)';
            $args[] = (string) $filters['active_on'];
        }
        $sql = "SELECT c.*, s.code AS source_code, s.name AS source_name
                FROM {$table} c
                LEFT JOIN {$sources} s ON s.id = c.source_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY c.starts_at DESC, c.id DESC';
        $rows = $wpdb->get_results($this->prepare($sql, $args), ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function attributeOrder(int $orderId, array $data): array
    {
        global $wpdb;
        $orders = $this->table('sales_orders');
        $order = $wpdb->get_row($wpdb->prepare("SELECT id, status, channel FROM {$orders} WHERE id = %d", $orderId), ARRAY_A);
        if (!is_array($order)) {
            throw new AnalyticsDomainException('Sales order not found.');
        }
        if ($data['source_id'] !== null) {
            $source = $this->sourceById((int) $data['source_id']);
            if (!(bool) $source['is_active']) {
                throw new AnalyticsDomainException('Order source must be active.');
            }
        }
        if ($data['campaign_id'] !== null) {
            $campaign = $this->campaignById((int) $data['campaign_id']);
            if (in_array((string) $campaign['status'], ['cancelled'], true)) {
                throw new AnalyticsDomainException('Cancelled campaign cannot receive attribution.');
            }
            if ($data['source_id'] !== null && $campaign['source_id'] !== null && (int) $campaign['source_id'] !== (int) $data['source_id']) {
                throw new AnalyticsDomainException('Campaign and source attribution are inconsistent.');
            }
        }
        $table = $this->table('order_attribution');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $orderId), ARRAY_A);
        if (is_array($existing)) {
            $normalized = $this->normalizeRow($existing);
            foreach (['source_id', 'campaign_id', 'branch_id', 'salesperson_user_id', 'province', 'city'] as $field) {
                if (($normalized[$field] ?? null) != ($data[$field] ?? null)) {
                    throw new AnalyticsDomainException('Order attribution is immutable and already differs.');
                }
            }
            $normalized['idempotent'] = true;

            return $normalized;
        }
        $inserted = $wpdb->insert($table, [
            'order_id' => $orderId,
            'source_id' => $data['source_id'],
            'campaign_id' => $data['campaign_id'],
            'branch_id' => $data['branch_id'],
            'salesperson_user_id' => $data['salesperson_user_id'],
            'province' => $data['province'],
            'city' => $data['city'],
            'attributed_by' => $data['attributed_by'],
            'created_at' => $this->now(),
        ], ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s']);
        $this->assertInserted($inserted, 'Unable to attribute sales order');
        $row = $this->row($table, (int) $wpdb->insert_id);
        $row['idempotent'] = false;

        return $row;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function recordPrice(array $data): array
    {
        global $wpdb;
        $products = $this->table('products');
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$products} WHERE id = %d AND is_active = 1", $data['product_id']));
        if ($exists === 0) {
            throw new AnalyticsDomainException('Active product not found for price history.');
        }
        $table = $this->table('price_history');
        $closed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET effective_to = %s
             WHERE product_id = %d AND channel = %s AND effective_to IS NULL AND effective_from < %s",
            $data['effective_from'],
            $data['product_id'],
            $data['channel'],
            $data['effective_from']
        ));
        if ($closed === false) {
            throw new RuntimeException('Unable to close prior price-history interval.');
        }
        $inserted = $wpdb->insert($table, [
            'price_key' => $this->uuid(),
            'product_id' => $data['product_id'],
            'channel' => $data['channel'],
            'purchase_price_irr' => $data['purchase_price_irr'],
            'cogs_irr' => $data['cogs_irr'],
            'selling_price_irr' => $data['selling_price_irr'],
            'effective_from' => $data['effective_from'],
            'effective_to' => null,
            'reason' => $data['reason'],
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => $this->now(),
        ], ['%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s']);
        $this->assertInserted($inserted, 'Unable to record product price history');

        return $this->row($table, (int) $wpdb->insert_id);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function priceHistory(array $filters = []): array
    {
        global $wpdb;
        $table = $this->table('price_history');
        $products = $this->table('products');
        $where = ['1=1'];
        $args = [];
        if (!empty($filters['product_id'])) {
            $where[] = 'h.product_id = %d';
            $args[] = (int) $filters['product_id'];
        }
        if (!empty($filters['channel'])) {
            $where[] = 'h.channel = %s';
            $args[] = (string) $filters['channel'];
        }
        $sql = "SELECT h.*, p.sku, p.name AS product_name
                FROM {$table} h JOIN {$products} p ON p.id = h.product_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY h.effective_from DESC, h.id DESC LIMIT 1000';
        $rows = $wpdb->get_results($this->prepare($sql, $args), ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createTarget(array $data): array
    {
        global $wpdb;
        $table = $this->table('analytics_targets');
        $inserted = $wpdb->insert($table, [
            'target_key' => $data['target_key'],
            'dimension_hash' => $data['dimension_hash'],
            'kpi' => $data['kpi'],
            'period_type' => $data['period_type'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'product_line' => $data['product_line'],
            'sales_channel' => $data['sales_channel'],
            'province' => $data['province'],
            'city' => $data['city'],
            'target_value' => $data['target_value'],
            'is_active' => 1,
            'created_by' => $data['created_by'],
            'created_at' => $this->now(),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']);
        $this->assertInserted($inserted, 'Unable to create analytics target');

        return $this->targetById((int) $wpdb->insert_id);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function targets(array $filters = []): array
    {
        global $wpdb;
        $table = $this->table('analytics_targets');
        $facts = $this->table('analytics_facts_daily');
        $where = ['t.is_active = 1'];
        $args = [];
        if (!empty($filters['kpi'])) {
            $where[] = 't.kpi = %s';
            $args[] = (string) $filters['kpi'];
        }
        if (!empty($filters['active_on'])) {
            $where[] = '%s BETWEEN t.starts_on AND t.ends_on';
            $args[] = (string) $filters['active_on'];
        }
        $sql = "SELECT t.*,
                COALESCE((SELECT SUM(CASE t.kpi
                    WHEN 'sales' THEN f.revenue_irr
                    WHEN 'gross_profit' THEN f.gross_profit_irr
                    WHEN 'order_count' THEN f.orders_count
                    ELSE 0 END)
                    FROM {$facts} f
                    WHERE f.fact_date BETWEEN t.starts_on AND t.ends_on
                      AND (t.product_line IS NULL OR f.product_line = t.product_line)
                      AND (t.sales_channel IS NULL OR f.sales_channel = t.sales_channel)
                      AND (t.province IS NULL OR f.province = t.province)
                      AND (t.city IS NULL OR f.city = t.city)), 0) AS actual_value
                FROM {$table} t WHERE " . implode(' AND ', $where) . '
                ORDER BY t.starts_on DESC, t.id DESC';
        $rows = $wpdb->get_results($this->prepare($sql, $args), ARRAY_A);

        return array_map(fn (array $row): array => $this->normalizeRow($row), is_array($rows) ? $rows : []);
    }
}
