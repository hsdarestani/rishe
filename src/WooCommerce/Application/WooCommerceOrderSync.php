<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Application;

use RuntimeException;
use Throwable;

trait WooCommerceOrderSync
{
    /** @return array<string, mixed> */
    public function syncOrder(int $orderId): array
    {
        $settings = $this->settings();
        if (!$this->enabled() || !(bool) $settings['sync_orders']) {
            return ['skipped' => true, 'reason' => 'disabled'];
        }
        $this->assertWarehouse((int) $settings['warehouse_id']);
        $order = wc_get_order($orderId);
        if (!$order) {
            throw new RuntimeException('سفارش ووکامرس پیدا نشد.');
        }
        if (self::$syncingOrder) {
            return ['skipped' => true, 'reason' => 'recursion'];
        }
        self::$syncingOrder = true;
        try {
            foreach ($order->get_items('line_item') as $item) {
                $product = $item->get_product();
                if (!$product) {
                    throw new RuntimeException('یکی از اقلام سفارش محصول معتبر ندارد.');
                }
                if ($this->byWooId((int) $product->get_id()) === null) {
                    if (!(bool) $settings['auto_map_products']) {
                        throw new RuntimeException('محصول سفارش به کالای ریشه متصل نشده است.');
                    }
                    $this->ensureMapping($product);
                }
            }
            $mapped = $this->orderMapper->map($this->orderPayload($order));
            $risheOrder = $this->sales->createOrder($mapped['order'], $this->actor());
            $status = (string) $risheOrder['status'];
            if ($mapped['cancelled'] && $status === 'pending_payment') {
                $this->sales->cancelOrder((int) $risheOrder['id'], $this->actor(), 'لغو سفارش در ووکامرس');
                $risheOrder = $this->sales->order((int) $risheOrder['id']);
            } elseif ($mapped['payment'] !== null && $status === 'pending_payment') {
                $risheOrder = $this->sales->capturePayment((int) $risheOrder['id'], $mapped['payment'], $this->actor());
                $status = (string) $risheOrder['status'];
            }
            if ($mapped['completed'] && in_array($status, ['paid', 'fulfilling'], true)) {
                $this->sales->completeOrder((int) $risheOrder['id'], $this->actor());
                $risheOrder = $this->sales->order((int) $risheOrder['id']);
            }
            $order->update_meta_data('_rishe_order_id', (int) $risheOrder['id']);
            $order->update_meta_data('_rishe_last_sync', gmdate('c'));
            $order->delete_meta_data('_rishe_sync_error');
            $order->save_meta_data();
            $this->rememberRun('sync_order', ['woocommerce_order_id' => $orderId, 'rishe_order_id' => (int) $risheOrder['id']]);

            return $risheOrder;
        } catch (Throwable $e) {
            $order->update_meta_data('_rishe_sync_error', $e->getMessage());
            $order->save_meta_data();
            $this->rememberError('sync_order', $e, ['woocommerce_order_id' => $orderId]);
            throw $e;
        } finally {
            self::$syncingOrder = false;
        }
    }

    /** @return array<string, mixed> */
    public function syncRefund(int $refundId, int $orderId): array
    {
        $settings = $this->settings();
        if (!$this->enabled() || !(bool) $settings['sync_refunds']) {
            return ['skipped' => true, 'reason' => 'disabled'];
        }
        $refund = wc_get_order($refundId);
        if (!$refund || !is_a($refund, 'WC_Order_Refund')) {
            throw new RuntimeException('اطلاعات مرجوعی ووکامرس معتبر نیست.');
        }
        if ((string) $refund->get_meta('_rishe_refund_synced', true) !== '') {
            return ['skipped' => true, 'reason' => 'already_synced'];
        }
        $warehouse = (int) $settings['warehouse_id'];
        $this->assertWarehouse($warehouse);
        $processed = 0;
        foreach ($refund->get_items('line_item') as $itemId => $item) {
            $quantity = abs((float) $item->get_quantity());
            $product = $item->get_product();
            if ($quantity <= 0 || !$product) {
                continue;
            }
            $row = $this->byWooId((int) $product->get_id());
            if ($row === null && (bool) $settings['auto_map_products']) {
                $row = $this->ensureMapping($product);
            }
            if ($row === null) {
                throw new RuntimeException('محصول مرجوعی به کالای ریشه متصل نشده است.');
            }
            $this->inventory->receiveStock([
                'product_id' => (int) $row['id'],
                'warehouse_id' => $warehouse,
                'batch_code' => substr('WC-REFUND-' . $refundId . '-' . $itemId, 0, 100),
                'quantity' => $this->decimal($quantity),
                'unit_cost_irr' => $this->lastCost((int) $row['id'], $warehouse),
                'received_at' => gmdate('Y-m-d H:i:s'),
                'reference_type' => 'woocommerce_refund',
                'reference_id' => (string) $refundId,
                'correlation_id' => 'woocommerce-refund-' . $refundId,
            ], $this->actor());
            $processed++;
        }
        $refund->update_meta_data('_rishe_refund_synced', gmdate('c'));
        $refund->save_meta_data();
        $financial = $this->syncFullRefundStatus($orderId, $refundId);
        $this->audit->record('woocommerce.refund.synced', 'woocommerce_refund', (string) $refundId, [
            'woocommerce_order_id' => $orderId,
            'items_processed' => $processed,
            'financial_status' => $financial,
        ]);

        return [
            'refund_id' => $refundId,
            'order_id' => $orderId,
            'items_processed' => $processed,
            'financial_status' => $financial,
        ];
    }

    /** @return array<string, mixed> */
    private function syncFullRefundStatus(int $orderId, int $refundId): array
    {
        global $wpdb;

        $wcOrder = wc_get_order($orderId);
        if (!$wcOrder) {
            return ['status' => 'woocommerce_order_missing'];
        }
        $total = (float) $wcOrder->get_total();
        $refunded = (float) $wcOrder->get_total_refunded();
        $isFull = $wcOrder->has_status('refunded') || ($total > 0 && $refunded + 0.0001 >= $total);
        if (!$isFull) {
            return ['status' => 'partial_refund', 'refunded' => $refunded, 'total' => $total];
        }

        $risheId = (int) $wcOrder->get_meta('_rishe_order_id', true);
        if ($risheId < 1) {
            $synced = $this->syncOrder($orderId);
            $risheId = (int) ($synced['id'] ?? 0);
        }
        if ($risheId < 1) {
            return ['status' => 'rishe_order_missing'];
        }

        $orders = $wpdb->prefix . 'rishe_sales_orders';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE id=%d FOR UPDATE",
            $risheId
        ), ARRAY_A);
        if (!is_array($row)) {
            return ['status' => 'rishe_order_missing', 'rishe_order_id' => $risheId];
        }
        if ((string) $row['status'] === 'refunded') {
            return ['status' => 'already_refunded', 'rishe_order_id' => $risheId];
        }
        if (!in_array((string) $row['status'], ['paid', 'fulfilling', 'completed'], true)) {
            return [
                'status' => 'not_refundable_state',
                'rishe_order_id' => $risheId,
                'current_status' => (string) $row['status'],
            ];
        }

        $reversalId = null;
        $voucherId = (int) ($row['accounting_voucher_id'] ?? 0);
        if ($voucherId > 0 && (string) ($row['accounting_status'] ?? '') === 'posted') {
            $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
            $voucher = $wpdb->get_row($wpdb->prepare(
                "SELECT id, fiscal_year, status FROM {$vouchers} WHERE id=%d",
                $voucherId
            ), ARRAY_A);
            if (is_array($voucher) && (string) $voucher['status'] === 'posted') {
                $reversalId = $this->accounting->reverseVoucher(
                    $voucherId,
                    (int) $voucher['fiscal_year'],
                    gmdate('Y-m-d'),
                    'برگشت حسابداری سفارش ووکامرس ' . $orderId . ' بابت مرجوعی ' . $refundId,
                    $this->actor()
                );
            }
        }

        $from = (string) $row['status'];
        $updated = $wpdb->update($orders, [
            'status' => 'refunded',
            'payment_status' => 'refunded',
            'fulfillment_status' => 'returned',
            'accounting_status' => $reversalId === null ? (string) $row['accounting_status'] : 'reversed',
            'updated_at' => current_time('mysql', true),
        ], [
            'id' => $risheId,
            'status' => $from,
        ], ['%s', '%s', '%s', '%s', '%s'], ['%d', '%s']);
        if ($updated !== 1) {
            throw new RuntimeException('ثبت وضعیت مرجوع‌شده برای سفارش ریشه ناموفق بود.');
        }
        $history = $wpdb->insert($wpdb->prefix . 'rishe_order_status_history', [
            'order_id' => $risheId,
            'from_status' => $from,
            'to_status' => 'refunded',
            'actor_user_id' => $this->actor(),
            'reason' => 'مرجوعی کامل سفارش ووکامرس ' . $orderId,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);
        if ($history === false) {
            throw new RuntimeException('ثبت تاریخچه مرجوعی سفارش ناموفق بود.');
        }

        return [
            'status' => 'fully_refunded',
            'rishe_order_id' => $risheId,
            'reversal_voucher_id' => $reversalId,
        ];
    }

    /** @return array{processed:int,synced:int,skipped:int,errors:list<string>} */
    public function importRecentOrders(int $limit = 50): array
    {
        $this->assertWooCommerce();
        $settings = $this->settings();
        $started = strtotime((string) ($settings['started_at'] ?? '')) ?: time();
        $ids = wc_get_orders([
            'limit' => max(1, min(250, $limit)),
            'return' => 'ids',
            'date_created' => '>=' . $started,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        $result = ['processed' => 0, 'synced' => 0, 'skipped' => 0, 'errors' => []];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $result['processed']++;
            try {
                $synced = $this->syncOrder((int) $id);
                ($synced['skipped'] ?? false) ? $result['skipped']++ : $result['synced']++;
            } catch (Throwable $e) {
                $result['errors'][] = sprintf('سفارش %d: %s', (int) $id, $e->getMessage());
            }
        }
        $this->rememberRun('import_orders', $result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function orderPayload(object $order): array
    {
        $lines = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $product->get_parent_id() ?: (int) $product->get_id(),
                'variation_id' => $product->is_type('variation') ? (int) $product->get_id() : 0,
                'quantity' => $this->decimal((float) $item->get_quantity()),
                'subtotal' => (string) $item->get_subtotal(),
                'total' => (string) $item->get_total(),
            ];
        }

        return [
            'id' => (string) $order->get_id(),
            'status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'customer_id' => (string) $order->get_customer_id(),
            'billing' => [
                'phone' => $order->get_billing_phone(),
                'first_name' => $order->get_billing_first_name() ?: 'مشتری',
                'last_name' => $order->get_billing_last_name() ?: 'ووکامرس',
                'email' => $order->get_billing_email(),
                'company' => $order->get_billing_company(),
            ],
            'line_items' => $lines,
            'transaction_id' => (string) $order->get_transaction_id(),
            'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->date('c') : null,
            'shipping_total' => (string) $order->get_shipping_total(),
            'total_tax' => (string) $order->get_total_tax(),
            'total' => (string) $order->get_total(),
        ];
    }
}
