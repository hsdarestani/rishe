<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure\WordPress;

use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SalesInsightsRestApi
{
    private const TARGET_OPTION = 'rishe_monthly_sales_targets_irr';
    private const ORDER_SCAN_LIMIT = 5000;

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $salesView = static fn (): bool => current_user_can('rishe_view_sales_dashboard')
            || current_user_can('manage_rishe');
        $customersView = static fn (): bool => current_user_can('rishe_view_customers')
            || current_user_can('manage_rishe');
        $targetManage = static fn (): bool => current_user_can('rishe_manage_sales_targets')
            || current_user_can('manage_rishe');

        register_rest_route('rishe/v1', '/sales-intelligence/report', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'report'],
            'permission_callback' => $salesView,
        ]);
        register_rest_route('rishe/v1', '/sales-intelligence/customers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'customers'],
            'permission_callback' => $customersView,
        ]);
        register_rest_route('rishe/v1', '/sales-intelligence/target', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'target'],
                'permission_callback' => $salesView,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'saveTarget'],
                'permission_callback' => $targetManage,
            ],
        ]);
    }

    public function report(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $this->assertWooCommerce();
            $from = $this->date((string) ($request->get_param('from') ?: gmdate('Y-m-01')), 'از تاریخ');
            $to = $this->date((string) ($request->get_param('to') ?: gmdate('Y-m-d')), 'تا تاریخ');
            if ($to < $from) {
                throw new RuntimeException('تا تاریخ باید بعد از از تاریخ باشد.');
            }

            $filters = [
                'channel' => sanitize_key((string) $request->get_param('channel')),
                'status' => sanitize_key((string) $request->get_param('status')),
                'seller_user_id' => max(0, (int) $request->get_param('seller_user_id')),
                'event_id' => max(0, (int) $request->get_param('event_id')),
                'customer' => trim(wp_strip_all_tags((string) $request->get_param('customer'))),
                'product_id' => max(0, (int) $request->get_param('product_id')),
            ];
            $rows = $this->salesRows($from, $to, $filters);
            $summary = $this->summarize($rows);
            $targetMonth = $this->month(
                (string) ($request->get_param('target_month') ?: substr($from, 0, 7))
            );
            $target = $this->targetAmount($targetMonth);
            $targetActual = $this->salesTotalForMonth($targetMonth);
            $deviation = $targetActual - $target;
            $deviationPercent = $target > 0
                ? round(($deviation / $target) * 100, 2)
                : null;

            return [
                'from' => $from,
                'to' => $to,
                'rows' => $rows,
                'summary' => $summary,
                'target' => [
                    'month' => $targetMonth,
                    'target_irr' => $target,
                    'actual_irr' => $targetActual,
                    'deviation_irr' => $deviation,
                    'deviation_percent' => $deviationPercent,
                    'progress_percent' => $target > 0
                        ? round(($targetActual / $target) * 100, 2)
                        : null,
                ],
                'filters' => $this->filterOptions($rows),
                'scan_limit' => self::ORDER_SCAN_LIMIT,
                'can_manage_target' => current_user_can('rishe_manage_sales_targets')
                    || current_user_can('manage_rishe'),
            ];
        });
    }

    public function customers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $this->assertWooCommerce();
            $search = mb_strtolower(trim(wp_strip_all_tags((string) $request->get_param('search'))));
            $channel = sanitize_key((string) $request->get_param('channel'));
            $minimumOrders = max(0, (int) $request->get_param('min_orders'));
            $sort = sanitize_key((string) ($request->get_param('sort') ?: 'recent'));
            $page = max(1, (int) ($request->get_param('page') ?: 1));
            $perPage = max(10, min(100, (int) ($request->get_param('per_page') ?: 50)));

            $aggregate = $this->customerAggregate();
            $rows = array_values($aggregate['rows']);
            $rows = array_values(array_filter($rows, function (array $row) use (
                $search,
                $channel,
                $minimumOrders
            ): bool {
                if ((int) $row['orders'] < $minimumOrders) {
                    return false;
                }
                if ($channel !== '' && !in_array($channel, $row['channels'], true)) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }
                $haystack = mb_strtolower(implode(' ', [
                    (string) $row['name'],
                    (string) $row['phone'],
                    (string) $row['email'],
                ]));

                return str_contains($haystack, $search);
            }));

            usort($rows, static function (array $left, array $right) use ($sort): int {
                if ($sort === 'spend') {
                    return (int) $right['total_spent_irr'] <=> (int) $left['total_spent_irr'];
                }
                if ($sort === 'orders') {
                    return (int) $right['orders'] <=> (int) $left['orders'];
                }
                if ($sort === 'name') {
                    return strcmp((string) $left['name'], (string) $right['name']);
                }

                return strcmp((string) $right['last_order_at'], (string) $left['last_order_at']);
            });

            $total = count($rows);
            $offset = ($page - 1) * $perPage;
            $paged = array_slice($rows, $offset, $perPage);

            return [
                'rows' => $paged,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => max(1, (int) ceil($total / $perPage)),
                'summary' => [
                    'customers' => count($aggregate['rows']),
                    'registered_customers' => $aggregate['registered'],
                    'guest_customers' => $aggregate['guests'],
                    'orders_scanned' => $aggregate['orders_scanned'],
                    'sales_irr' => $aggregate['sales_irr'],
                ],
                'truncated' => $aggregate['orders_scanned'] >= self::ORDER_SCAN_LIMIT,
            ];
        });
    }

    public function target(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $month = $this->month((string) ($request->get_param('month') ?: gmdate('Y-m')));

            return [
                'month' => $month,
                'target_irr' => $this->targetAmount($month),
                'can_manage' => current_user_can('rishe_manage_sales_targets')
                    || current_user_can('manage_rishe'),
            ];
        });
    }

    public function saveTarget(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : $request->get_params();
            $month = $this->month((string) ($payload['month'] ?? ''));
            $target = filter_var(
                $payload['target_irr'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if ($target === false) {
                throw new RuntimeException('تارگت ماهانه باید مبلغ معتبر و به ریال باشد.');
            }

            $targets = get_option(self::TARGET_OPTION, []);
            $targets = is_array($targets) ? $targets : [];
            $targets[$month] = (int) $target;
            ksort($targets);
            update_option(self::TARGET_OPTION, $targets, false);

            return [
                'month' => $month,
                'target_irr' => (int) $target,
            ];
        });
    }

    /**
     * @param array{channel:string,status:string,seller_user_id:int,event_id:int,customer:string,product_id:int} $filters
     * @return list<array<string, mixed>>
     */
    private function salesRows(string $from, string $to, array $filters): array
    {
        $args = [
            'limit' => self::ORDER_SCAN_LIMIT,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_created' => $from . '...' . $to . ' 23:59:59',
            'return' => 'objects',
        ];
        if ($filters['status'] !== '') {
            $args['status'] = $filters['status'];
        }

        $orders = wc_get_orders($args);
        $rows = [];
        $sellerNames = [];
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!is_object($order) || !method_exists($order, 'get_id')) {
                continue;
            }
            $channel = $this->channel($order);
            $sellerId = (int) $order->get_meta('_rishe_seller_user_id', true);
            $eventId = (int) $order->get_meta('_rishe_event_id', true);
            $customerName = trim((string) $order->get_formatted_billing_full_name()) ?: 'مشتری مهمان';
            $phone = (string) $order->get_billing_phone();
            $email = (string) $order->get_billing_email();
            $productIds = [];
            $itemNames = [];
            foreach ($order->get_items('line_item') as $item) {
                $productId = (int) $item->get_product_id();
                $variationId = (int) $item->get_variation_id();
                if ($productId > 0) {
                    $productIds[] = $productId;
                }
                if ($variationId > 0) {
                    $productIds[] = $variationId;
                }
                $itemNames[] = (string) $item->get_name() . ' × ' . (string) $item->get_quantity();
            }

            if ($filters['channel'] !== '' && $channel !== $filters['channel']) {
                continue;
            }
            if ($filters['seller_user_id'] > 0 && $sellerId !== $filters['seller_user_id']) {
                continue;
            }
            if ($filters['event_id'] > 0 && $eventId !== $filters['event_id']) {
                continue;
            }
            if ($filters['product_id'] > 0 && !in_array($filters['product_id'], $productIds, true)) {
                continue;
            }
            if ($filters['customer'] !== '') {
                $needle = mb_strtolower($filters['customer']);
                $haystack = mb_strtolower($customerName . ' ' . $phone . ' ' . $email);
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            if ($sellerId > 0 && !isset($sellerNames[$sellerId])) {
                $user = get_user_by('id', $sellerId);
                $sellerNames[$sellerId] = $user ? (string) $user->display_name : 'کاربر #' . $sellerId;
            }
            $created = $order->get_date_created();
            $subtotal = 0.0;
            foreach ($order->get_items('line_item') as $item) {
                $subtotal += (float) $item->get_subtotal();
            }
            $discount = (float) $order->get_discount_total();
            foreach ($order->get_items('fee') as $fee) {
                $feeTotal = (float) $fee->get_total();
                if ($feeTotal < 0) {
                    $discount += abs($feeTotal);
                }
            }
            $status = (string) $order->get_status();
            $counted = $this->countsAsSale($status);

            $rows[] = [
                'order_id' => (int) $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'created_at' => $created ? $created->date('Y-m-d H:i:s') : '',
                'status' => $status,
                'status_label' => function_exists('wc_get_order_status_name')
                    ? (string) wc_get_order_status_name($status)
                    : $status,
                'channel' => $channel,
                'seller_user_id' => $sellerId,
                'seller_name' => $sellerId > 0 ? $sellerNames[$sellerId] : '',
                'event_id' => $eventId,
                'event_name' => (string) $order->get_meta('_rishe_event_name', true),
                'customer_id' => (int) $order->get_customer_id(),
                'customer_name' => $customerName,
                'phone' => $phone,
                'email' => $email,
                'products' => $itemNames,
                'product_ids' => array_values(array_unique($productIds)),
                'items_count' => (int) $order->get_item_count(),
                'subtotal_irr' => $this->storeMoneyToIrr($subtotal),
                'discount_irr' => $this->storeMoneyToIrr($discount),
                'total_irr' => $this->storeMoneyToIrr((float) $order->get_total()),
                'payment_method' => (string) $order->get_payment_method_title(),
                'counted_as_sale' => $counted,
                'edit_url' => method_exists($order, 'get_edit_order_url')
                    ? (string) $order->get_edit_order_url()
                    : '',
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int|float> */
    private function summarize(array $rows): array
    {
        $sales = 0;
        $discount = 0;
        $orders = 0;
        $customers = [];
        foreach ($rows as $row) {
            if (!(bool) $row['counted_as_sale']) {
                continue;
            }
            ++$orders;
            $sales += (int) $row['total_irr'];
            $discount += (int) $row['discount_irr'];
            $customerId = (int) $row['customer_id'];
            $identity = $customerId > 0
                ? 'u:' . $customerId
                : 'g:' . $this->normalizePhone((string) $row['phone']) . ':' . mb_strtolower((string) $row['email']);
            $customers[$identity] = true;
        }

        return [
            'orders' => $orders,
            'sales_irr' => $sales,
            'discount_irr' => $discount,
            'average_order_irr' => $orders > 0 ? intdiv($sales, $orders) : 0,
            'customers' => count($customers),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function filterOptions(array $rows): array
    {
        $channels = [];
        $statuses = [];
        $sellers = [];
        $events = [];
        $products = [];
        foreach ($rows as $row) {
            $channels[(string) $row['channel']] = (string) $row['channel'];
            $statuses[(string) $row['status']] = (string) $row['status_label'];
            if ((int) $row['seller_user_id'] > 0) {
                $sellers[(int) $row['seller_user_id']] = (string) $row['seller_name'];
            }
            if ((int) $row['event_id'] > 0) {
                $events[(int) $row['event_id']] = (string) $row['event_name'];
            }
            foreach ((array) $row['product_ids'] as $index => $productId) {
                $productId = (int) $productId;
                if ($productId <= 0 || isset($products[$productId])) {
                    continue;
                }
                $product = wc_get_product($productId);
                $products[$productId] = $product ? (string) $product->get_name() : 'کالا #' . $productId;
            }
        }
        ksort($sellers);
        ksort($events);
        asort($products);

        return [
            'channels' => array_values($channels),
            'statuses' => $this->keyValueRows($statuses),
            'sellers' => $this->keyValueRows($sellers),
            'events' => $this->keyValueRows($events),
            'products' => $this->keyValueRows($products),
        ];
    }

    /** @return array{rows:array<string,array<string,mixed>>,registered:int,guests:int,orders_scanned:int,sales_irr:int} */
    private function customerAggregate(): array
    {
        $rows = [];
        $phoneMap = [];
        $emailMap = [];
        $registered = 0;
        $customers = wc_get_customers([
            'limit' => self::ORDER_SCAN_LIMIT,
            'orderby' => 'registered_date',
            'order' => 'DESC',
        ]);
        foreach (is_array($customers) ? $customers : [] as $customer) {
            if (!is_object($customer) || !method_exists($customer, 'get_id')) {
                continue;
            }
            $id = (int) $customer->get_id();
            if ($id <= 0) {
                continue;
            }
            ++$registered;
            $phone = $this->normalizePhone((string) $customer->get_billing_phone());
            $email = mb_strtolower(trim((string) $customer->get_email()));
            $key = 'u:' . $id;
            $rows[$key] = $this->emptyCustomerRow(
                $id,
                trim((string) $customer->get_first_name() . ' ' . (string) $customer->get_last_name())
                    ?: (string) $customer->get_display_name(),
                $phone,
                $email,
                (string) ($customer->get_date_created()?->date('Y-m-d H:i:s') ?? '')
            );
            if ($phone !== '') {
                $phoneMap[$phone] = $key;
            }
            if ($email !== '') {
                $emailMap[$email] = $key;
            }
        }

        $orders = wc_get_orders([
            'limit' => self::ORDER_SCAN_LIMIT,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);
        $ordersScanned = 0;
        $sales = 0;
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!is_object($order) || !method_exists($order, 'get_id')) {
                continue;
            }
            ++$ordersScanned;
            $customerId = (int) $order->get_customer_id();
            $phone = $this->normalizePhone((string) $order->get_billing_phone());
            $email = mb_strtolower(trim((string) $order->get_billing_email()));
            $name = trim((string) $order->get_formatted_billing_full_name()) ?: 'مشتری مهمان';
            if ($customerId > 0) {
                $key = 'u:' . $customerId;
            } elseif ($phone !== '' && isset($phoneMap[$phone])) {
                $key = $phoneMap[$phone];
            } elseif ($email !== '' && isset($emailMap[$email])) {
                $key = $emailMap[$email];
            } elseif ($phone !== '') {
                $key = 'p:' . $phone;
            } elseif ($email !== '') {
                $key = 'e:' . $email;
            } else {
                $key = 'o:' . (int) $order->get_id();
            }
            if (!isset($rows[$key])) {
                $rows[$key] = $this->emptyCustomerRow($customerId, $name, $phone, $email, '');
            }
            if ($rows[$key]['name'] === '' || $rows[$key]['name'] === 'مشتری مهمان') {
                $rows[$key]['name'] = $name;
            }
            if ($rows[$key]['phone'] === '' && $phone !== '') {
                $rows[$key]['phone'] = $phone;
            }
            if ($rows[$key]['email'] === '' && $email !== '') {
                $rows[$key]['email'] = $email;
            }

            $created = $order->get_date_created();
            $createdAt = $created ? $created->date('Y-m-d H:i:s') : '';
            $rows[$key]['orders'] = (int) $rows[$key]['orders'] + 1;
            $rows[$key]['first_order_at'] = $this->earlier(
                (string) $rows[$key]['first_order_at'],
                $createdAt
            );
            $rows[$key]['last_order_at'] = $this->later(
                (string) $rows[$key]['last_order_at'],
                $createdAt
            );
            $channel = $this->channel($order);
            if (!in_array($channel, $rows[$key]['channels'], true)) {
                $rows[$key]['channels'][] = $channel;
            }
            if ($this->countsAsSale((string) $order->get_status())) {
                $amount = $this->storeMoneyToIrr((float) $order->get_total());
                $rows[$key]['total_spent_irr'] = (int) $rows[$key]['total_spent_irr'] + $amount;
                $sales += $amount;
            }
        }

        $guests = 0;
        foreach ($rows as &$row) {
            $ordersCount = (int) $row['orders'];
            $row['average_order_irr'] = $ordersCount > 0
                ? intdiv((int) $row['total_spent_irr'], $ordersCount)
                : 0;
            if ((int) $row['customer_id'] <= 0) {
                ++$guests;
            }
        }
        unset($row);

        return [
            'rows' => $rows,
            'registered' => $registered,
            'guests' => $guests,
            'orders_scanned' => $ordersScanned,
            'sales_irr' => $sales,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCustomerRow(
        int $customerId,
        string $name,
        string $phone,
        string $email,
        string $registeredAt
    ): array {
        return [
            'customer_id' => $customerId,
            'name' => trim($name),
            'phone' => $phone,
            'email' => $email,
            'registered_at' => $registeredAt,
            'orders' => 0,
            'total_spent_irr' => 0,
            'average_order_irr' => 0,
            'first_order_at' => '',
            'last_order_at' => '',
            'channels' => [],
        ];
    }

    private function salesTotalForMonth(string $month): int
    {
        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        $from = sprintf('%04d-%02d-01', $year, $monthNumber);
        $to = gmdate('Y-m-t', strtotime($from . ' 00:00:00 UTC'));
        $orders = wc_get_orders([
            'limit' => self::ORDER_SCAN_LIMIT,
            'date_created' => $from . '...' . $to . ' 23:59:59',
            'return' => 'objects',
        ]);
        $total = 0;
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!is_object($order) || !$this->countsAsSale((string) $order->get_status())) {
                continue;
            }
            $total += $this->storeMoneyToIrr((float) $order->get_total());
        }

        return $total;
    }

    private function targetAmount(string $month): int
    {
        $targets = get_option(self::TARGET_OPTION, []);
        if (!is_array($targets)) {
            return 0;
        }

        return max(0, (int) ($targets[$month] ?? 0));
    }

    private function channel(object $order): string
    {
        $channel = sanitize_key((string) $order->get_meta('_rishe_sales_channel', true));
        if ($channel !== '') {
            return $channel;
        }
        $createdVia = sanitize_key((string) $order->get_created_via());
        if ($createdVia === 'rishe-event-app') {
            return 'event';
        }

        return $createdVia !== '' ? $createdVia : 'website';
    }

    private function countsAsSale(string $status): bool
    {
        return !in_array($status, ['cancelled', 'failed', 'refunded', 'trash'], true);
    }

    private function storeMoneyToIrr(float $value): int
    {
        $currency = strtoupper((string) get_woocommerce_currency());

        return (int) round(in_array($currency, ['IRT', 'TMN', 'TOMAN'], true) ? $value * 10 : $value);
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    private function date(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException($label . ' معتبر نیست.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new RuntimeException($label . ' معتبر نیست.');
        }

        return $value;
    }

    private function month(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches) !== 1) {
            throw new RuntimeException('ماه تارگت معتبر نیست.');
        }
        $month = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            throw new RuntimeException('ماه تارگت معتبر نیست.');
        }

        return $matches[1] . '-' . $matches[2];
    }

    /** @param array<int|string, string> $items @return list<array{id:int|string,label:string}> */
    private function keyValueRows(array $items): array
    {
        $result = [];
        foreach ($items as $value => $label) {
            $result[] = ['id' => $value, 'label' => $label];
        }

        return $result;
    }

    private function earlier(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return $left < $right ? $left : $right;
    }

    private function later(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return $left > $right ? $left : $right;
    }

    private function assertWooCommerce(): void
    {
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_customers')) {
            throw new RuntimeException('ووکامرس فعال نیست.');
        }
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), 200);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_sales_intelligence_error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            error_log('[Rishe sales intelligence] ' . $exception->getMessage());

            return new WP_REST_Response([
                'code' => 'rishe_sales_intelligence_unexpected',
                'message' => 'خطای غیرمنتظره در گزارش فروش رخ داد.',
            ], 500);
        }
    }
}
