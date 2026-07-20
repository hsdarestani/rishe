<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Infrastructure\WordPress;

use Rishe\WooCommerce\Application\WooCommerceSyncService;
use Throwable;

final class WooCommerceSyncRuntime
{
    public const ORDER_HOOK = 'rishe/woocommerce/sync_order';
    public const REFUND_HOOK = 'rishe/woocommerce/sync_refund';

    public function __construct(private ?WooCommerceSyncService $service = null)
    {
        $this->service ??= new WooCommerceSyncService();
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [$this, 'ensureSchedule']);
        add_action(WooCommerceSyncService::CRON_HOOK, [$this, 'reconcile']);
        add_action(WooCommerceSyncService::ASYNC_STOCK_HOOK, [$this, 'syncStock'], 10, 2);
        add_action(self::ORDER_HOOK, [$this, 'syncOrder']);
        add_action(self::REFUND_HOOK, [$this, 'syncRefund'], 10, 2);

        add_action('woocommerce_checkout_order_processed', [$this, 'queueCheckoutOrder'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'queueStoreApiOrder'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'queueStatusOrder'], 20, 4);
        add_action('woocommerce_payment_complete', [$this, 'queuePaymentOrder'], 20, 1);
        add_action('woocommerce_refund_created', [$this, 'queueRefundCreated'], 20, 2);

        add_filter('woocommerce_hold_stock_for_checkout', [$this, 'preventCheckoutHold'], 20, 1);
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'preventNativeReduction'], 20, 2);
        add_filter('woocommerce_can_restore_order_stock', [$this, 'preventNativeRestore'], 20, 2);

        add_action('woocommerce_process_product_meta', [$this, 'productSaved'], 50, 1);
        add_action('woocommerce_save_product_variation', [$this, 'variationSaved'], 50, 2);
        add_action('woocommerce_rest_insert_product_object', [$this, 'restProductSaved'], 50, 3);
        add_action('woocommerce_rest_insert_product_variation_object', [$this, 'restProductSaved'], 50, 3);
        add_action('rishe/audit_recorded', [$this, 'inventoryChanged'], 20, 1);
    }

    /** @param array<string, array<string, mixed>> $schedules @return array<string, array<string, mixed>> */
    public function cronSchedules(array $schedules): array
    {
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => 'هر پانزده دقیقه',
        ];

        return $schedules;
    }

    public function ensureSchedule(): void
    {
        $settings = $this->service->settings();
        if ((bool) $settings['enabled'] && (bool) $settings['sync_stock'] && !wp_next_scheduled(WooCommerceSyncService::CRON_HOOK)) {
            $this->service->reschedule($settings);
        }
    }

    public function queueCheckoutOrder(int $orderId): void
    {
        // Reservation must be created during checkout itself; delaying it can oversell.
        $this->syncOrder($orderId);
    }

    public function queueStoreApiOrder(object $order): void
    {
        // Store API checkout follows the same synchronous reservation rule.
        $this->syncOrder((int) $order->get_id());
    }

    public function queueStatusOrder(int $orderId, string $from, string $to, object $order): void
    {
        unset($from, $to, $order);
        $this->queue(self::ORDER_HOOK, [$orderId]);
    }

    public function queuePaymentOrder(int $orderId): void
    {
        $this->queue(self::ORDER_HOOK, [$orderId]);
    }

    /** @param array<string, mixed> $args */
    public function queueRefundCreated(int $refundId, array $args): void
    {
        // WooCommerce only returns stock when the merchant selected "restock refunded items".
        if (!(bool) ($args['restock_items'] ?? false)) {
            return;
        }
        $orderId = (int) ($args['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->queue(self::REFUND_HOOK, [$refundId, $orderId]);
        }
    }

    public function syncOrder(int $orderId): void
    {
        $this->safe('sync_order', fn () => $this->service->syncOrder($orderId));
    }

    public function syncRefund(int $refundId, int $orderId): void
    {
        $this->safe('sync_refund', fn () => $this->service->syncRefund($refundId, $orderId));
    }

    public function reconcile(): void
    {
        if (!$this->service->enabled()) {
            return;
        }
        $this->safe('reconcile', fn () => $this->service->reconcile());
    }

    public function syncStock(int $productId, int $warehouseId): void
    {
        $this->safe('sync_stock', fn () => $this->service->pushStock($productId, $warehouseId));
    }

    public function preventCheckoutHold(bool $allowed): bool
    {
        // Rishe owns reservations for every mapped WooCommerce item. Keeping both
        // WooCommerce held stock and Rishe reservations would count the same order twice.
        return $this->service->ownsStock() ? false : $allowed;
    }

    public function preventNativeReduction(bool $allowed, mixed $order): bool
    {
        $order = is_object($order) ? $order : (function_exists('wc_get_order') ? wc_get_order((int) $order) : null);
        if ($allowed && $order && $this->service->shouldOwnOrderStock($order)) {
            return false;
        }

        return $allowed;
    }

    public function preventNativeRestore(bool $allowed, mixed $order): bool
    {
        return $this->preventNativeReduction($allowed, $order);
    }

    public function productSaved(int $productId): void
    {
        $this->handleManualProductSave($productId);
    }

    public function variationSaved(int $variationId, int $index): void
    {
        unset($index);
        $this->handleManualProductSave($variationId);
    }

    public function restProductSaved(object $product, mixed $request, bool $creating): void
    {
        unset($request, $creating);
        $this->handleProduct($product, true);
    }

    /** @param array<string, mixed> $event */
    public function inventoryChanged(array $event): void
    {
        if (!$this->service->ownsStock() || WooCommerceSyncService::isPulling()) {
            return;
        }
        foreach ($this->service->affectedByAudit($event) as $pair) {
            $this->queue(WooCommerceSyncService::ASYNC_STOCK_HOOK, [
                (int) $pair['product_id'],
                (int) $pair['warehouse_id'],
            ]);
        }
    }

    private function handleManualProductSave(int $productId): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }
        $product = wc_get_product($productId);
        if ($product) {
            $this->handleProduct($product, false);
        }
    }

    private function handleProduct(object $product, bool $fromRest): void
    {
        unset($fromRest);
        $settings = $this->service->settings();
        if (!$this->service->enabled() || WooCommerceSyncService::isPushing()) {
            return;
        }
        $this->safe('map_product', function () use ($product, $settings): void {
            if ((bool) $settings['auto_map_products']) {
                $this->service->ensureMapping($product);
            }
            if ((bool) $settings['sync_stock'] && (bool) $settings['pull_manual_wc_stock']) {
                $this->service->pullProduct($product);
            }
        });
    }

    /** @param list<mixed> $args */
    private function queue(string $hook, array $args): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, 'rishe-woocommerce');

            return;
        }
        wp_schedule_single_event(time() + 5, $hook, $args);
    }

    /** @param callable(): mixed $operation */
    private function safe(string $operation, callable $operationCallback): void
    {
        try {
            $operationCallback();
        } catch (Throwable $exception) {
            update_option('rishe_woocommerce_last_error', [
                'operation' => $operation,
                'message' => $exception->getMessage(),
                'occurred_at' => gmdate('c'),
            ], false);
            error_log('[Rishe WooCommerce] ' . $operation . ': ' . $exception->getMessage());
            do_action('rishe/woocommerce/error', $exception, $operation);
        }
    }
}
