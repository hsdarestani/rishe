<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Application;

use Rishe\Inventory\Domain\Quantity;
use RuntimeException;
use Throwable;

trait WooCommerceProductSync
{
    /** @return array{processed:int,mapped:int,skipped:int,errors:list<string>} */
    public function importProducts(): array
    {
        $this->assertWooCommerce();
        $ids = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $result = ['processed' => 0, 'mapped' => 0, 'skipped' => 0, 'errors' => []];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $result['processed']++;
            try {
                $product = wc_get_product((int) $id);
                if (!$product || ($product->is_type('variable') && !$product->managing_stock())) {
                    $result['skipped']++;
                    continue;
                }
                $this->ensureMapping($product);
                $result['mapped']++;
            } catch (Throwable $e) {
                $result['errors'][] = sprintf('محصول %d: %s', (int) $id, $e->getMessage());
            }
        }
        $this->rememberRun('import_products', $result);

        return $result;
    }

    /** @return array<string, mixed> */
    public function ensureMapping(object $wcProduct): array
    {
        global $wpdb;
        $wcId = (int) $wcProduct->get_id();
        if ($wcId < 1) {
            throw new RuntimeException('شناسه محصول ووکامرس معتبر نیست.');
        }
        $table = $wpdb->prefix . 'rishe_products';
        $existing = $this->byWooId($wcId, false);
        $sku = trim((string) $wcProduct->get_sku()) ?: 'WC-' . $wcId;
        $name = trim(wp_strip_all_tags((string) $wcProduct->get_name())) ?: 'محصول ووکامرس ' . $wcId;
        if ($existing !== null) {
            $updated = $wpdb->update($table, [
                'sku' => $this->uniqueSku($sku, (int) $existing['id']),
                'name' => $name,
                'is_active' => 1,
                'updated_at' => current_time('mysql', true),
            ], ['id' => (int) $existing['id']], ['%s', '%s', '%d', '%s'], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('به‌روزرسانی نگاشت محصول ناموفق بود: ' . $wpdb->last_error);
            }

            return $this->byWooId($wcId) ?? $existing;
        }
        $bySku = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE sku=%s", $sku), ARRAY_A);
        if (is_array($bySku) && (int) ($bySku['wc_product_id'] ?? 0) === 0) {
            $updated = $wpdb->update($table, [
                'wc_product_id' => $wcId,
                'name' => $name,
                'is_active' => 1,
                'updated_at' => current_time('mysql', true),
            ], ['id' => (int) $bySku['id']], ['%d', '%s', '%d', '%s'], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('اتصال محصول ووکامرس به ریشه ناموفق بود: ' . $wpdb->last_error);
            }

            return $this->byWooId($wcId) ?? $bySku;
        }
        $id = $this->inventory->createProduct([
            'sku' => $this->uniqueSku($sku),
            'name' => $name,
            'base_unit' => 'عدد',
            'inventory_method' => 'fifo',
            'wc_product_id' => $wcId,
        ]);
        update_post_meta($wcId, '_rishe_product_id', $id);

        return $this->byWooId($wcId) ?? ['id' => $id, 'wc_product_id' => $wcId];
    }

    /** @return array{processed:int,changed:int,skipped:int,errors:list<string>} */
    public function pushAll(?int $risheProductId = null): array
    {
        $settings = $this->settings();
        $warehouse = (int) $settings['warehouse_id'];
        $this->assertWarehouse($warehouse);
        $result = ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($this->mappedRows($risheProductId) as $row) {
            $result['processed']++;
            try {
                $this->pushStock((int) $row['id'], $warehouse) ? $result['changed']++ : $result['skipped']++;
            } catch (Throwable $e) {
                $result['errors'][] = sprintf('%s: %s', (string) $row['sku'], $e->getMessage());
            }
        }
        $this->rememberRun('push_stock', $result);

        return $result;
    }

    public function pushStock(int $risheProductId, ?int $warehouseId = null): bool
    {
        if (!$this->ownsStock() || self::$pulling) {
            return false;
        }
        $settings = $this->settings();
        $warehouseId = $warehouseId ?: (int) $settings['warehouse_id'];
        if ($warehouseId !== (int) $settings['warehouse_id']) {
            return false;
        }
        $row = $this->byRisheId($risheProductId);
        if ($row === null || (int) ($row['wc_product_id'] ?? 0) < 1) {
            return false;
        }
        $wc = wc_get_product((int) $row['wc_product_id']);
        if (!$wc) {
            throw new RuntimeException('محصول متناظر ووکامرس پیدا نشد.');
        }
        $quantity = $this->available($risheProductId, $warehouseId);
        $current = $wc->managing_stock() ? (float) ($wc->get_stock_quantity() ?? 0) : null;
        if ($current !== null && abs($current - $quantity) <= 0.0001) {
            return false;
        }
        self::$pushing = true;
        try {
            if (!$wc->managing_stock()) {
                $wc->set_manage_stock(true);
                $wc->save();
            }
            wc_update_product_stock($wc, $quantity, 'set', true);
            $wc->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
            $wc->save();
            update_post_meta((int) $row['wc_product_id'], '_rishe_product_id', $risheProductId);
            update_post_meta((int) $row['wc_product_id'], '_rishe_last_stock_sync', gmdate('c'));
        } finally {
            self::$pushing = false;
        }

        return true;
    }

    /** @return array{processed:int,changed:int,skipped:int,errors:list<string>} */
    public function pullAll(?int $wcProductId = null): array
    {
        $settings = $this->settings();
        $this->assertWarehouse((int) $settings['warehouse_id']);
        $rows = $wcProductId ? [$this->byWooId($wcProductId)] : $this->mappedRows();
        $result = ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result['processed']++;
            try {
                $wc = wc_get_product((int) $row['wc_product_id']);
                if (!$wc || !$wc->managing_stock()) {
                    $result['skipped']++;
                    continue;
                }
                $this->pullProduct($wc) ? $result['changed']++ : $result['skipped']++;
            } catch (Throwable $e) {
                $result['errors'][] = sprintf('%s: %s', (string) $row['sku'], $e->getMessage());
            }
        }
        $this->rememberRun('pull_stock', $result);

        return $result;
    }

    public function pullProduct(object $wcProduct): bool
    {
        $settings = $this->settings();
        if (!$this->enabled() || !(bool) $settings['sync_stock'] || self::$pushing) {
            return false;
        }
        $warehouse = (int) $settings['warehouse_id'];
        $this->assertWarehouse($warehouse);
        $row = $this->byWooId((int) $wcProduct->get_id());
        if ($row === null && (bool) $settings['auto_map_products']) {
            $row = $this->ensureMapping($wcProduct);
        }
        if ($row === null || !$wcProduct->managing_stock()) {
            return false;
        }
        $target = max(0.0, (float) ($wcProduct->get_stock_quantity() ?? 0));
        $delta = round($target - $this->available((int) $row['id'], $warehouse), 4);
        if (abs($delta) <= 0.0001) {
            return false;
        }
        self::$pulling = true;
        try {
            $corr = 'wc-stock-' . (int) $wcProduct->get_id() . '-' . gmdate('YmdHis');
            if ($delta > 0) {
                $this->inventory->receiveStock([
                    'product_id' => (int) $row['id'],
                    'warehouse_id' => $warehouse,
                    'batch_code' => substr('WC-SYNC-' . (int) $wcProduct->get_id() . '-' . gmdate('YmdHis'), 0, 100),
                    'quantity' => $this->decimal($delta),
                    'unit_cost_irr' => $this->lastCost((int) $row['id'], $warehouse),
                    'received_at' => gmdate('Y-m-d H:i:s'),
                    'reference_type' => 'woocommerce_stock_sync',
                    'reference_id' => (string) $wcProduct->get_id(),
                    'correlation_id' => $corr,
                ], $this->actor());
            } else {
                $reservation = $this->inventory->reserveStock([
                    'product_id' => (int) $row['id'],
                    'warehouse_id' => $warehouse,
                    'quantity' => $this->decimal(abs($delta)),
                    'reference_type' => 'woocommerce_stock_sync',
                    'reference_id' => (string) $wcProduct->get_id() . ':' . gmdate('YmdHis') . ':' . wp_rand(1000, 9999),
                    'correlation_id' => $corr,
                ], $this->actor());
                $this->inventory->commitReservation($reservation, $this->actor());
            }
        } finally {
            self::$pulling = false;
        }
        update_post_meta((int) $wcProduct->get_id(), '_rishe_last_stock_pull', gmdate('c'));

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function mappedRows(?int $id = null): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rishe_products';
        $rows = $id === null
            ? $wpdb->get_results("SELECT * FROM {$table} WHERE is_active=1 AND wc_product_id IS NOT NULL ORDER BY id", ARRAY_A)
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND is_active=1 AND wc_product_id IS NOT NULL", $id), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    private function byWooId(int $wcId, bool $active = true): ?array
    {
        global $wpdb;
        $activeSql = $active ? ' AND is_active=1' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_products WHERE wc_product_id=%d{$activeSql}",
            $wcId
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function byRisheId(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_products WHERE id=%d AND is_active=1",
            $id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function available(int $productId, int $warehouseId): float
    {
        global $wpdb;
        $scaled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity_on_hand-quantity_reserved),0) FROM {$wpdb->prefix}rishe_inventory_batches
             WHERE product_id=%d AND warehouse_id=%d AND status='active'",
            $productId,
            $warehouseId
        ));

        return $scaled / Quantity::SCALE;
    }

    private function lastCost(int $productId, int $warehouseId): int
    {
        global $wpdb;
        $cost = $wpdb->get_var($wpdb->prepare(
            "SELECT unit_cost_irr FROM {$wpdb->prefix}rishe_inventory_batches WHERE product_id=%d AND warehouse_id=%d
             ORDER BY received_at DESC,id DESC LIMIT 1",
            $productId,
            $warehouseId
        ));

        return $cost === null ? max(0, (int) $this->settings()['default_unit_cost_irr']) : max(0, (int) $cost);
    }

    private function uniqueSku(string $candidate, ?int $excludeId = null): string
    {
        global $wpdb;
        $base = substr(sanitize_text_field($candidate), 0, 90) ?: 'WC';
        $sku = $base;
        for ($i = 1; $i < 10000; $i++) {
            $sql = "SELECT id FROM {$wpdb->prefix}rishe_products WHERE sku=%s";
            $args = [$sku];
            if ($excludeId !== null) {
                $sql .= ' AND id<>%d';
                $args[] = $excludeId;
            }
            if ($wpdb->get_var($wpdb->prepare($sql, ...$args)) === null) {
                return $sku;
            }
            $sku = substr($base, 0, 84) . '-' . $i;
        }
        throw new RuntimeException('ساخت شناسه یکتای کالا ممکن نشد.');
    }
}
