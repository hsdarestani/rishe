<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\WooCommerce\Application\WooCommerceSyncService;
use Throwable;
use WP_REST_Request;

/**
 * Keeps the Event POS catalog in sync with manual WooCommerce stock edits when
 * the full Rishe <-> WooCommerce stock integration is disabled.
 *
 * When the full integration owns stock, WooCommerceSyncRuntime already handles
 * manual product saves and Rishe remains the source of truth. Pulling again on
 * every catalog request in that mode could resurrect stock before an async push
 * finishes, so this runtime deliberately stays out of the way there.
 */
final class EventCatalogStockRefresh
{
    private InventoryService $inventory;
    private WooCommerceSyncService $woocommerce;
    private AuditLogger $audit;

    public function __construct()
    {
        $this->audit = new AuditLogger();
        $this->inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            new TransactionManager(),
            $this->audit
        );
        $this->woocommerce = new WooCommerceSyncService();
    }

    public function register(): void
    {
        add_filter('rest_request_before_callbacks', [$this, 'beforeRestCallback'], 10, 3);
    }

    public function beforeRestCallback(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        unset($handler);

        if ($response !== null) {
            return $response;
        }
        $route = (string) $request->get_route();
        if (!preg_match('#^/rishe/v1/event-sales/events/(\d+)/catalog$#', $route, $matches)) {
            return $response;
        }
        if (
            !current_user_can('rishe_sell_event')
            && !current_user_can('rishe_manage_sales')
            && !current_user_can('manage_rishe')
        ) {
            return $response;
        }

        try {
            $this->refresh((int) $matches[1]);
            delete_option('rishe_event_catalog_stock_refresh_last_error');
        } catch (Throwable $exception) {
            update_option('rishe_event_catalog_stock_refresh_last_error', [
                'event_id' => (int) $matches[1],
                'message' => $exception->getMessage(),
                'occurred_at' => gmdate('c'),
            ], false);
            error_log('[Rishe Event Catalog Stock Refresh] ' . $exception->getMessage());
        }

        return $response;
    }

    private function refresh(int $eventId): void
    {
        if ($eventId < 1 || !function_exists('wc_get_products')) {
            return;
        }

        // When the full integration is enabled, its save hooks and stock owner
        // semantics are authoritative. Never pull Woo stock opportunistically.
        if ($this->woocommerce->enabled()) {
            return;
        }

        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT id,warehouse_id,status FROM {$wpdb->prefix}rishe_sales_events WHERE id=%d LIMIT 1",
            $eventId
        ), ARRAY_A);
        if (!is_array($event) || (int) ($event['warehouse_id'] ?? 0) < 1) {
            return;
        }
        $warehouseId = (int) $event['warehouse_id'];
        $settings = $this->woocommerce->settings();
        if (!(bool) ($settings['sync_stock'] ?? true) || !(bool) ($settings['pull_manual_wc_stock'] ?? true)) {
            return;
        }

        // A saved Woo warehouse is an explicit boundary: do not copy one global
        // Woo stock figure into a different event warehouse.
        $configuredWarehouse = (int) ($settings['warehouse_id'] ?? 0);
        if ($configuredWarehouse > 0 && $configuredWarehouse !== $warehouseId) {
            return;
        }

        // With no configured Woo warehouse, only infer the event warehouse when
        // all active events share one warehouse. This avoids duplicating global
        // Woo stock across several independent event warehouses.
        if ($configuredWarehouse < 1) {
            $activeWarehouses = $wpdb->get_col(
                "SELECT DISTINCT warehouse_id FROM {$wpdb->prefix}rishe_sales_events WHERE status='active' AND warehouse_id IS NOT NULL"
            );
            $activeWarehouses = array_values(array_unique(array_map('intval', is_array($activeWarehouses) ? $activeWarehouses : [])));
            if (count($activeWarehouses) !== 1 || (int) $activeWarehouses[0] !== $warehouseId) {
                return;
            }
        }

        $products = wc_get_products([
            'limit' => 300,
            'status' => ['publish', 'private'],
            'type' => ['simple', 'variation'],
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach (is_array($products) ? $products : [] as $product) {
            if (
                !is_object($product)
                || !method_exists($product, 'get_id')
                || !method_exists($product, 'managing_stock')
                || !$product->managing_stock()
            ) {
                continue;
            }
            $this->syncProduct($product, $warehouseId, $eventId);
        }
    }

    private function syncProduct(object $product, int $warehouseId, int $eventId): void
    {
        global $wpdb;

        $wcProductId = (int) $product->get_id();
        if ($wcProductId < 1) {
            return;
        }
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rishe_products WHERE wc_product_id=%d AND is_active=1 LIMIT 1",
            $wcProductId
        ), ARRAY_A);
        if (!is_array($mapping)) {
            $mapping = $this->woocommerce->ensureMapping($product);
        }
        $risheProductId = (int) ($mapping['id'] ?? 0);
        if ($risheProductId < 1) {
            return;
        }

        $targetScaled = max(0, (int) round(((float) ($product->get_stock_quantity() ?? 0)) * 10000));
        $currentScaled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity_on_hand-quantity_reserved),0)
             FROM {$wpdb->prefix}rishe_inventory_batches
             WHERE product_id=%d AND warehouse_id=%d AND status='active'",
            $risheProductId,
            $warehouseId
        ));
        $deltaScaled = $targetScaled - $currentScaled;
        if ($deltaScaled === 0) {
            return;
        }

        $correlation = 'event-catalog-wc-' . $wcProductId . '-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999);
        $quantity = number_format(abs($deltaScaled) / 10000, 4, '.', '');
        $actor = get_current_user_id();

        if ($deltaScaled > 0) {
            $lastCost = $wpdb->get_var($wpdb->prepare(
                "SELECT unit_cost_irr FROM {$wpdb->prefix}rishe_inventory_batches
                 WHERE product_id=%d AND warehouse_id=%d
                 ORDER BY received_at DESC,id DESC LIMIT 1",
                $risheProductId,
                $warehouseId
            ));
            $this->inventory->receiveStock([
                'product_id' => $risheProductId,
                'warehouse_id' => $warehouseId,
                'batch_code' => substr('WC-EVENT-' . $wcProductId . '-' . gmdate('YmdHis'), 0, 100),
                'quantity' => $quantity,
                'unit_cost_irr' => max(0, (int) ($lastCost ?? ($this->woocommerce->settings()['default_unit_cost_irr'] ?? 0))),
                'received_at' => gmdate('Y-m-d H:i:s'),
                'reference_type' => 'woocommerce_event_catalog_sync',
                'reference_id' => (string) $wcProductId,
                'correlation_id' => $correlation,
            ], $actor);
        } else {
            $reservation = $this->inventory->reserveStock([
                'product_id' => $risheProductId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'reference_type' => 'woocommerce_event_catalog_sync',
                'reference_id' => $wcProductId . ':' . gmdate('YmdHis') . ':' . wp_rand(1000, 9999),
                'correlation_id' => $correlation,
            ], $actor);
            $this->inventory->commitReservation($reservation, $actor);
        }

        update_post_meta($wcProductId, '_rishe_last_event_catalog_stock_pull', gmdate('c'));
        $this->audit->record('event_sales.catalog.stock_refreshed', 'woocommerce_product', (string) $wcProductId, [
            'event_id' => $eventId,
            'warehouse_id' => $warehouseId,
            'target_scaled' => $targetScaled,
            'previous_scaled' => $currentScaled,
        ]);
    }
}
