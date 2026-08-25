<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

use Throwable;
use WC_Order;

final class EventCustomerSyncRuntime
{
    private bool $syncing = false;

    public function register(): void
    {
        add_action('woocommerce_after_order_object_save', [$this, 'attachCustomer'], 20, 1);
    }

    public function attachCustomer(object $order): void
    {
        if ($this->syncing || !$order instanceof WC_Order) {
            return;
        }
        if ((int) $order->get_customer_id() > 0) {
            return;
        }
        if ((int) $order->get_meta('_rishe_event_sale_id', true) < 1) {
            return;
        }
        $channel = sanitize_key((string) $order->get_meta('_rishe_sales_channel', true));
        if ($channel !== 'event' && (string) $order->get_created_via() !== 'rishe-event-app') {
            return;
        }

        try {
            $this->syncing = true;
            $customerId = $this->resolveCustomer($order);
            if ($customerId < 1) {
                return;
            }
            $order->set_customer_id($customerId);
            $order->update_meta_data('_rishe_event_customer_id', $customerId);
            $order->update_meta_data('_rishe_event_customer_linked', 1);
            $order->save();
        } catch (Throwable $exception) {
            error_log('[Rishe event customer] ' . $exception->getMessage());
        } finally {
            $this->syncing = false;
        }
    }

    private function resolveCustomer(WC_Order $order): int
    {
        $phone = $this->normalizePhone((string) $order->get_billing_phone());
        if ($phone === '') {
            return 0;
        }
        $name = trim((string) $order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = 'مشتری ایونت';
        }

        if ($phone !== '') {
            $existing = $this->customerByPhone($phone);
            if ($existing > 0) {
                $this->updateCustomerProfile($existing, $name, $phone);

                return $existing;
            }
        }

        $baseLogin = $phone !== ''
            ? 'rishe_' . $phone
            : 'rishe_event_' . (int) $order->get_id();
        $login = sanitize_user($baseLogin, true);
        if ($login === '') {
            $login = 'rishe_event_' . wp_generate_password(10, false, false);
        }
        $candidate = $login;
        $suffix = 1;
        while (username_exists($candidate)) {
            $candidate = $login . '_' . $suffix;
            ++$suffix;
        }

        $userId = wp_insert_user([
            'user_login' => $candidate,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $name,
            'first_name' => $name,
            'role' => 'customer',
        ]);
        if (is_wp_error($userId)) {
            throw new \RuntimeException('ساخت مشتری ووکامرس انجام نشد: ' . $userId->get_error_message());
        }

        $customerId = (int) $userId;
        $this->updateCustomerProfile($customerId, $name, $phone);

        return $customerId;
    }

    private function customerByPhone(string $phone): int
    {
        global $wpdb;
        $userId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key='billing_phone' AND meta_value=%s
             ORDER BY user_id ASC LIMIT 1",
            $phone
        ));

        return $userId > 0 ? $userId : 0;
    }

    private function updateCustomerProfile(int $userId, string $name, string $phone): void
    {
        update_user_meta($userId, 'billing_first_name', $name);
        update_user_meta($userId, 'first_name', $name);
        if ($phone !== '') {
            update_user_meta($userId, 'billing_phone', $phone);
        }
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '0098')) {
            return '0' . substr($digits, 4);
        }
        if (str_starts_with($digits, '98') && strlen($digits) >= 12) {
            return '0' . substr($digits, 2);
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }
}
