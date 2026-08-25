<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingReviewService;
use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Sales\Infrastructure\WpdbSalesRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\WooCommerce\Application\WooCommerceSyncService;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class EventSalesRestApi
{
    private TransactionManager $transactions;
    private InventoryService $inventory;
    private WpdbSalesRepository $sales;
    private WooCommerceSyncService $woocommerce;
    private AccountingService $accounting;
    private AccountingReviewService $reviews;
    private AuditLogger $audit;

    public function __construct()
    {
        $this->transactions = new TransactionManager();
        $this->audit = new AuditLogger();
        $this->inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            $this->transactions,
            $this->audit
        );
        $this->sales = new WpdbSalesRepository();
        $this->woocommerce = new WooCommerceSyncService();
        $this->accounting = new AccountingService(
            new WpdbAccountingRepository(),
            $this->transactions,
            $this->audit
        );
        $this->reviews = new AccountingReviewService($this->accounting);
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $admin = static fn (): bool => current_user_can('rishe_manage_sales') || current_user_can('manage_rishe');
        $seller = static fn (): bool => current_user_can('rishe_sell_event')
            || current_user_can('rishe_manage_sales')
            || current_user_can('manage_rishe');

        register_rest_route('rishe/v1', '/event-sales/events', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'events'],
                'permission_callback' => $admin,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createEvent'],
                'permission_callback' => $admin,
            ],
        ]);
        register_rest_route('rishe/v1', '/event-sales/events/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'updateEvent'],
            'permission_callback' => $admin,
        ]);
        register_rest_route('rishe/v1', '/event-sales/recent', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'recentSales'],
            'permission_callback' => $admin,
        ]);
        register_rest_route('rishe/v1', '/event-sales/bootstrap', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'bootstrap'],
            'permission_callback' => $seller,
        ]);
        register_rest_route('rishe/v1', '/event-sales/events/(?P<id>\d+)/catalog', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'catalog'],
            'permission_callback' => $seller,
        ]);
        register_rest_route('rishe/v1', '/event-sales/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sync'],
            'permission_callback' => $seller,
        ]);
    }

    public function events(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(function (): array {
            global $wpdb;
            $warehouses = $wpdb->get_results(
                "SELECT id,name,code,type FROM {$wpdb->prefix}rishe_warehouses WHERE is_active=1 ORDER BY name",
                ARRAY_A
            );
            $users = get_users([
                'fields' => ['ID', 'display_name'],
                'orderby' => 'display_name',
                'order' => 'ASC',
            ]);
            $rows = $this->eventRows(false);
            foreach ($rows as &$row) {
                $row['sellers'] = $this->event((int) $row['id'])['sellers'];
            }
            unset($row);

            return [
                'rows' => $rows,
                'warehouses' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'code' => (string) $row['code'],
                    'type' => (string) $row['type'],
                ], is_array($warehouses) ? $warehouses : []),
                'users' => array_map(static fn (object $user): array => [
                    'id' => (int) $user->ID,
                    'name' => (string) $user->display_name,
                ], is_array($users) ? $users : []),
            ];
        });
    }

    public function createEvent(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            global $wpdb;
            $data = $this->payload($request);
            $name = $this->requiredText($data['name'] ?? null, 'نام ایونت', 191);
            $warehouseId = $this->positiveId($data['warehouse_id'] ?? null, 'انبار ایونت');
            $this->assertWarehouse($warehouseId);
            $startsAt = $this->dateTime($data['starts_at'] ?? null, 'زمان شروع');
            $endsAt = $this->dateTime($data['ends_at'] ?? null, 'زمان پایان');
            if ($endsAt <= $startsAt) {
                throw new RuntimeException('زمان پایان باید بعد از زمان شروع باشد.');
            }
            $status = $this->eventStatus($data['status'] ?? 'draft');
            $now = current_time('mysql', true);
            $inserted = $wpdb->insert($wpdb->prefix . 'rishe_sales_events', [
                'public_id' => wp_generate_uuid4(),
                'name' => $name,
                'location' => $this->nullableText($data['location'] ?? null, 255),
                'warehouse_id' => $warehouseId,
                'status' => $status,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $this->nullableText($data['notes'] ?? null, 2000),
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
            if ($inserted === false) {
                throw new RuntimeException('ساخت ایونت انجام نشد: ' . $wpdb->last_error);
            }
            $eventId = (int) $wpdb->insert_id;
            $this->replaceSellers($eventId, $data['seller_user_ids'] ?? []);
            $this->audit->record('event_sales.event.created', 'sales_event', (string) $eventId, [
                'name' => $name,
                'warehouse_id' => $warehouseId,
                'status' => $status,
            ]);

            return $this->event($eventId);
        }, 201);
    }

    public function updateEvent(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            global $wpdb;
            $eventId = (int) $request['id'];
            $current = $this->event($eventId);
            $data = $this->payload($request);
            $warehouseId = isset($data['warehouse_id'])
                ? $this->positiveId($data['warehouse_id'], 'انبار ایونت')
                : (int) $current['warehouse_id'];
            $this->assertWarehouse($warehouseId);
            $startsAt = isset($data['starts_at'])
                ? $this->dateTime($data['starts_at'], 'زمان شروع')
                : (string) $current['starts_at'];
            $endsAt = isset($data['ends_at'])
                ? $this->dateTime($data['ends_at'], 'زمان پایان')
                : (string) $current['ends_at'];
            if ($endsAt <= $startsAt) {
                throw new RuntimeException('زمان پایان باید بعد از زمان شروع باشد.');
            }
            $updated = $wpdb->update($wpdb->prefix . 'rishe_sales_events', [
                'name' => isset($data['name'])
                    ? $this->requiredText($data['name'], 'نام ایونت', 191)
                    : (string) $current['name'],
                'location' => array_key_exists('location', $data)
                    ? $this->nullableText($data['location'], 255)
                    : $current['location'],
                'warehouse_id' => $warehouseId,
                'status' => isset($data['status'])
                    ? $this->eventStatus($data['status'])
                    : (string) $current['status'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => array_key_exists('notes', $data)
                    ? $this->nullableText($data['notes'], 2000)
                    : $current['notes'],
                'updated_at' => current_time('mysql', true),
            ], ['id' => $eventId], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'], ['%d']);
            if ($updated === false) {
                throw new RuntimeException('به‌روزرسانی ایونت انجام نشد.');
            }
            if (array_key_exists('seller_user_ids', $data)) {
                $this->replaceSellers($eventId, $data['seller_user_ids']);
            }
            $this->audit->record('event_sales.event.updated', 'sales_event', (string) $eventId, [
                'status' => $data['status'] ?? $current['status'],
            ]);

            return $this->event($eventId);
        });
    }

    public function recentSales(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            global $wpdb;
            $limit = max(1, min(200, (int) ($request->get_param('limit') ?: 50)));
            $sales = $wpdb->prefix . 'rishe_event_sales';
            $events = $wpdb->prefix . 'rishe_sales_events';
            $users = $wpdb->users;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, e.name AS event_name, u.display_name AS seller_name
                 FROM {$sales} s INNER JOIN {$events} e ON e.id=s.event_id
                 LEFT JOIN {$users} u ON u.ID=s.seller_user_id
                 ORDER BY s.occurred_at DESC, s.id DESC LIMIT %d",
                $limit
            ), ARRAY_A);

            return ['rows' => array_map([$this, 'formatSale'], is_array($rows) ? $rows : [])];
        });
    }

    public function bootstrap(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(function (): array {
            $user = wp_get_current_user();

            return [
                'user' => [
                    'id' => (int) $user->ID,
                    'name' => (string) $user->display_name,
                ],
                'events' => $this->eventRows(true),
                'server_time' => gmdate('c'),
                'version' => RISHE_VERSION,
            ];
        });
    }

    public function catalog(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $event = $this->event((int) $request['id']);
            $this->assertSellerAccess((int) $event['id']);
            if (!function_exists('wc_get_products')) {
                throw new RuntimeException('ووکامرس فعال نیست.');
            }
            global $wpdb;
            $products = wc_get_products([
                'limit' => 300,
                'status' => ['publish', 'private'],
                'type' => ['simple', 'variation'],
                'orderby' => 'name',
                'order' => 'ASC',
            ]);
            $rows = [];
            foreach (is_array($products) ? $products : [] as $product) {
                if (!is_object($product) || !method_exists($product, 'get_id') || !$product->is_purchasable()) {
                    continue;
                }
                $mapping = $this->sales->productByWooCommerceId((int) $product->get_id());
                if ($mapping === null) {
                    try {
                        $mapping = $this->woocommerce->ensureMapping($product);
                    } catch (Throwable) {
                        continue;
                    }
                }
                $scaled = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(quantity_on_hand-quantity_reserved),0)
                     FROM {$wpdb->prefix}rishe_inventory_batches
                     WHERE product_id=%d AND warehouse_id=%d AND status='active'",
                    (int) $mapping['id'],
                    (int) $event['warehouse_id']
                ));
                $rows[] = [
                    'wc_product_id' => (int) $product->get_id(),
                    'rishe_product_id' => (int) $mapping['id'],
                    'name' => wp_strip_all_tags((string) $product->get_name()),
                    'sku' => (string) $product->get_sku(),
                    'price_irr' => $this->storeMoneyToIrr((string) $product->get_price()),
                    'available' => $scaled / 10000,
                    'image' => wp_get_attachment_image_url((int) $product->get_image_id(), 'thumbnail') ?: '',
                ];
            }

            return ['event' => $event, 'products' => $rows, 'cached_at' => gmdate('c')];
        });
    }

    public function sync(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);
            $sales = $payload['sales'] ?? null;
            if (!is_array($sales) || $sales === []) {
                throw new RuntimeException('حداقل یک فروش برای همگام‌سازی لازم است.');
            }
            if (count($sales) > 100) {
                throw new RuntimeException('در هر همگام‌سازی حداکثر ۱۰۰ فروش قابل ارسال است.');
            }
            $result = ['synced' => [], 'failed' => []];
            foreach ($sales as $sale) {
                if (!is_array($sale)) {
                    continue;
                }
                $uuid = sanitize_text_field((string) ($sale['client_uuid'] ?? ''));
                try {
                    $result['synced'][] = $this->syncSale($sale);
                } catch (Throwable $exception) {
                    $this->recordSyncFailure($uuid, $exception->getMessage());
                    $result['failed'][] = ['client_uuid' => $uuid, 'message' => $exception->getMessage()];
                }
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function syncSale(array $data): array
    {
        if (!function_exists('wc_create_order') || !function_exists('wc_get_product')) {
            throw new RuntimeException('ووکامرس فعال نیست.');
        }
        $clientUuid = strtolower(trim((string) ($data['client_uuid'] ?? '')));
        if (!wp_is_uuid($clientUuid)) {
            throw new RuntimeException('شناسه آفلاین فروش معتبر نیست.');
        }
        $event = $this->event($this->positiveId($data['event_id'] ?? null, 'ایونت'));
        $this->assertSellerAccess((int) $event['id']);
        if ((string) $event['status'] !== 'active') {
            throw new RuntimeException('ایونت برای ثبت فروش فعال نیست.');
        }
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new RuntimeException('سبد فروش خالی است.');
        }
        $normalized = $this->normalizeLines($rawLines);
        $subtotal = array_sum(array_column($normalized, 'line_total_irr'));
        $discount = 0;
        $total = $subtotal - $discount;
        if ($total < 1) {
            throw new RuntimeException('مبلغ نهایی فروش باید بیشتر از صفر باشد.');
        }
        $paid = $this->money($data['paid_irr'] ?? $total, 'مبلغ پرداختی', 0);
        if ($paid > $total) {
            throw new RuntimeException('مبلغ پرداختی نمی‌تواند بیشتر از مبلغ نهایی باشد.');
        }
        $paymentMethod = sanitize_key((string) ($data['payment_method'] ?? 'cash'));
        if (!in_array($paymentMethod, ['cash', 'pos', 'card'], true)) {
            throw new RuntimeException('روش پرداخت معتبر نیست.');
        }
        $occurredAt = $this->dateTime($data['occurred_at'] ?? gmdate('Y-m-d H:i:s'), 'زمان فروش');
        $customerName = $this->requiredText($data['customer_name'] ?? null, 'نام مشتری', 191);
        $customerMobile = $this->normalizeMobile((string) ($data['customer_mobile'] ?? '')) ?: null;
        $commercial = [
            'event_id' => (int) $event['id'],
            'seller_user_id' => get_current_user_id(),
            'customer_name' => $customerName,
            'customer_mobile' => $customerMobile,
            'subtotal_irr' => $subtotal,
            'discount_irr' => $discount,
            'total_irr' => $total,
            'paid_irr' => $paid,
            'payment_method' => $paymentMethod,
            'occurred_at' => $occurredAt,
            'lines' => array_map(static fn (array $line): array => [
                'wc_product_id' => $line['wc_product_id'],
                'quantity_scaled' => $line['quantity_scaled'],
                'unit_price_irr' => $line['unit_price_irr'],
            ], $normalized),
        ];
        $hash = hash('sha256', (string) wp_json_encode($commercial));
        $existing = $this->existingSale($clientUuid);
        if ($existing !== null && (string) $existing['status'] === 'synced') {
            if (!hash_equals((string) $existing['payload_hash'], $hash)) {
                throw new RuntimeException('این فروش آفلاین قبلاً با اطلاعات متفاوت ثبت شده است.');
            }

            return $this->formatSale($existing) + ['idempotent' => true];
        }

        return (function () use (
            $clientUuid,
            $hash,
            $event,
            $normalized,
            $customerName,
            $customerMobile,
            $subtotal,
            $discount,
            $total,
            $paid,
            $paymentMethod,
            $occurredAt
        ): array {
            global $wpdb;
            $now = current_time('mysql', true);
            $existing = $this->existingSale($clientUuid);
            $inserted = $existing === null ? $wpdb->insert($wpdb->prefix . 'rishe_event_sales', [
                'public_id' => wp_generate_uuid4(),
                'client_uuid' => $clientUuid,
                'payload_hash' => $hash,
                'event_id' => (int) $event['id'],
                'seller_user_id' => get_current_user_id(),
                'wc_order_id' => null,
                'rishe_order_id' => null,
                'customer_name' => $customerName,
                'customer_mobile' => $customerMobile,
                'subtotal_irr' => $subtotal,
                'discount_irr' => $discount,
                'total_irr' => $total,
                'paid_irr' => $paid,
                'cogs_irr' => 0,
                'payment_method' => $paymentMethod,
                'accounting_voucher_id' => null,
                'accounting_status' => 'pending_configuration',
                'status' => 'processing',
                'occurred_at' => $occurredAt,
                'synced_at' => null,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s',
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ]) : 1;
            if ($inserted === false) {
                $duplicate = $this->existingSale($clientUuid);
                if ($duplicate !== null && hash_equals((string) $duplicate['payload_hash'], $hash)) {
                    return $this->formatSale($duplicate) + ['idempotent' => true];
                }
                throw new RuntimeException('ثبت اولیه فروش ایونت انجام نشد: ' . $wpdb->last_error);
            }
            $saleId = $existing === null ? (int) $wpdb->insert_id : (int) $existing['id'];
            $order = !empty($existing['wc_order_id']) ? wc_get_order((int) $existing['wc_order_id']) : null;
            $order = $order ?: wc_create_order(['status' => 'pending', 'created_via' => 'rishe-event-app']);
            if (!$order) {
                throw new RuntimeException('ساخت سفارش ووکامرس انجام نشد.');
            }
            $order->update_meta_data('_rishe_event_sale_id', $saleId);
            $order->update_meta_data('_rishe_event_inventory_committed', 1);
            $order->update_meta_data('_rishe_sales_channel', 'event');
            $order->update_meta_data('_rishe_warehouse_id', (int) $event['warehouse_id']);
            $order->update_meta_data('_rishe_event_id', (int) $event['id']);
            $order->update_meta_data('_rishe_event_name', (string) $event['name']);
            $order->update_meta_data('_rishe_seller_user_id', get_current_user_id());
            $order->update_meta_data('_rishe_event_paid_irr', $paid);
            $order->update_meta_data('_rishe_event_balance_irr', $total - $paid);
            $order->set_billing_first_name($customerName);
            $order->set_billing_last_name('');
            if ($customerMobile !== null) {
                $order->set_billing_phone($customerMobile);
            }
            $currency = strtoupper((string) get_woocommerce_currency());
            $order->set_currency($currency);
            $order->save();
            $wpdb->update($wpdb->prefix . 'rishe_event_sales', [
                'wc_order_id' => (int) $order->get_id(),
                'status' => 'order_created',
                'updated_at' => $now,
            ], ['id' => $saleId], ['%d', '%s', '%s'], ['%d']);
            $existingProductIds = [];
            foreach ($order->get_items('line_item') as $orderItem) {
                $existingProductIds[] = (int) $orderItem->get_product_id();
                $variationId = (int) $orderItem->get_variation_id();
                if ($variationId > 0) {
                    $existingProductIds[] = $variationId;
                }
            }
            $cogs = 0;
            foreach ($normalized as $line) {
                $product = wc_get_product((int) $line['wc_product_id']);
                if (!$product) {
                    throw new RuntimeException('یکی از کالاهای فروش در ووکامرس پیدا نشد.');
                }
                if (!in_array((int) $line['wc_product_id'], $existingProductIds, true)) {
                    $order->add_product($product, $line['quantity_scaled'] / 10000, [
                        'subtotal' => $this->irrToStoreMoney((int) $line['line_total_irr']),
                        'total' => $this->irrToStoreMoney((int) $line['line_total_irr']),
                    ]);
                    $existingProductIds[] = (int) $line['wc_product_id'];
                }
                $reservationId = $this->inventory->reserveStock([
                    'product_id' => (int) $line['rishe_product_id'],
                    'warehouse_id' => (int) $event['warehouse_id'],
                    'quantity' => $this->decimalQuantity((int) $line['quantity_scaled']),
                    'reference_type' => 'event_sale',
                    'reference_id' => $clientUuid . ':' . (int) $line['wc_product_id'],
                    'correlation_id' => 'event-sale-' . $clientUuid,
                ], get_current_user_id());
                $committed = $this->inventory->commitReservation($reservationId, get_current_user_id());
                $cogs += (int) $committed['cogs_irr'];
                $lineInserted = $wpdb->replace($wpdb->prefix . 'rishe_event_sale_lines', [
                    'event_sale_id' => $saleId,
                    'wc_product_id' => (int) $line['wc_product_id'],
                    'rishe_product_id' => (int) $line['rishe_product_id'],
                    'product_name' => (string) $line['product_name'],
                    'sku' => $line['sku'],
                    'quantity_scaled' => (int) $line['quantity_scaled'],
                    'unit_price_irr' => (int) $line['unit_price_irr'],
                    'line_total_irr' => (int) $line['line_total_irr'],
                    'created_at' => $now,
                ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s']);
                if ($lineInserted === false) {
                    throw new RuntimeException('ثبت اقلام فروش ایونت انجام نشد: ' . $wpdb->last_error);
                }
            }
            if ($discount > 0) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name('تخفیف ایونت');
                $fee->set_amount(-1 * $this->irrToStoreMoney($discount));
                $fee->set_total(-1 * $this->irrToStoreMoney($discount));
                $order->add_item($fee);
            }
            $methodTitles = [
                'cash' => 'نقدی', 'pos' => 'کارت‌خوان', 'card' => 'کارت‌به‌کارت',
                'transfer' => 'انتقال بانکی', 'credit' => 'اعتباری', 'mixed' => 'ترکیبی', 'other' => 'سایر',
            ];
            $order->set_payment_method('rishe_event_' . $paymentMethod);
            $order->set_payment_method_title($methodTitles[$paymentMethod]);
            $order->calculate_totals(false);
            $order->add_order_note(sprintf(
                'فروش ایونت %s توسط %s — پرداخت: %s از %s تومان',
                (string) $event['name'],
                wp_get_current_user()->display_name,
                number_format($paid / 10),
                number_format($total / 10)
            ));
            $order->set_status($paid >= $total ? 'completed' : 'on-hold');
            $order->save();

            $voucherId = $this->createEventVoucher(
                $saleId,
                $total,
                $paid,
                $cogs,
                $paymentMethod,
                $clientUuid
            );
            $updated = $wpdb->update($wpdb->prefix . 'rishe_event_sales', [
                'wc_order_id' => (int) $order->get_id(),
                'cogs_irr' => $cogs,
                'accounting_voucher_id' => $voucherId,
                'accounting_status' => $voucherId === null ? 'pending_configuration' : 'pending_approval',
                'status' => 'synced',
                'synced_at' => $now,
                'updated_at' => $now,
            ], ['id' => $saleId], ['%d', '%d', '%d', '%s', '%s', '%s', '%s'], ['%d']);
            if ($updated !== 1) {
                throw new RuntimeException('نهایی‌کردن فروش ایونت انجام نشد.');
            }
            $this->audit->record('event_sales.sale.synced', 'event_sale', (string) $saleId, [
                'event_id' => (int) $event['id'],
                'woocommerce_order_id' => (int) $order->get_id(),
                'total_irr' => $total,
                'paid_irr' => $paid,
                'cogs_irr' => $cogs,
            ], 'event-sale-' . $clientUuid);

            return $this->formatSale($this->existingSale($clientUuid) ?? []) + ['idempotent' => false];
        })();
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function normalizeLines(array $lines): array
    {
        $result = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('یکی از اقلام فروش معتبر نیست.');
            }
            $wcProductId = $this->positiveId($line['wc_product_id'] ?? null, 'کالا');
            if (isset($seen[$wcProductId])) {
                throw new RuntimeException('هر کالا فقط یک‌بار باید در سبد باشد.');
            }
            $seen[$wcProductId] = true;
            $product = wc_get_product($wcProductId);
            if (!$product || !$product->is_purchasable()) {
                throw new RuntimeException('کالای انتخاب‌شده قابل فروش نیست.');
            }
            $mapping = $this->sales->productByWooCommerceId($wcProductId);
            if ($mapping === null) {
                $mapping = $this->woocommerce->ensureMapping($product);
            }
            $quantity = (float) ($line['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > 100000) {
                throw new RuntimeException('تعداد کالا معتبر نیست.');
            }
            $quantityScaled = (int) round($quantity * 10000);
            $unitPrice = $this->storeMoneyToIrr((string) $product->get_price());
            if ($unitPrice < 1) {
                throw new RuntimeException('قیمت فروش کالا در ووکامرس معتبر نیست.');
            }
            $lineTotal = intdiv($quantityScaled * $unitPrice, 10000);
            $result[] = [
                'wc_product_id' => $wcProductId,
                'rishe_product_id' => (int) $mapping['id'],
                'product_name' => wp_strip_all_tags((string) $product->get_name()),
                'sku' => (string) $product->get_sku(),
                'quantity_scaled' => $quantityScaled,
                'unit_price_irr' => $unitPrice,
                'line_total_irr' => $lineTotal,
            ];
        }

        return $result;
    }

    private function createEventVoucher(
        int $saleId,
        int $total,
        int $paid,
        int $cogs,
        string $paymentMethod,
        string $clientUuid
    ): ?int {
        $mapping = get_option('rishe_sales_accounting_mapping', []);
        if (!is_array($mapping)) {
            return null;
        }
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        $settlement = (int) ($mapping['settlement_subsidiary_ledger_id'] ?? 0);
        $sales = (int) ($mapping['sales_subsidiary_ledger_id'] ?? 0);
        $cogsLedger = (int) ($mapping['cogs_subsidiary_ledger_id'] ?? 0);
        $inventory = (int) ($mapping['inventory_subsidiary_ledger_id'] ?? 0);
        $receivable = (int) ($mapping['event_receivable_subsidiary_ledger_id'] ?? $settlement);
        if ($fiscalYear < 1 || $settlement < 1 || $sales < 1 || ($cogs > 0 && ($cogsLedger < 1 || $inventory < 1))) {
            return null;
        }
        $lines = [];
        if ($paid > 0) {
            $lines[] = $this->journalLine(
                $settlement,
                $mapping['settlement_floating_detail_id'] ?? null,
                $paid,
                0,
                'دریافت فروش ایونت #' . $saleId . ' - ' . $paymentMethod
            );
        }
        if ($total > $paid) {
            $lines[] = $this->journalLine(
                $receivable,
                $mapping['event_receivable_floating_detail_id'] ?? null,
                $total - $paid,
                0,
                'مانده دریافتنی فروش ایونت #' . $saleId
            );
        }
        $lines[] = $this->journalLine(
            $sales,
            $mapping['sales_floating_detail_id'] ?? null,
            0,
            $total,
            'درآمد فروش ایونت #' . $saleId
        );
        if ($cogs > 0) {
            $lines[] = $this->journalLine(
                $cogsLedger,
                $mapping['cogs_floating_detail_id'] ?? null,
                $cogs,
                0,
                'بهای تمام‌شده فروش ایونت #' . $saleId
            );
            $lines[] = $this->journalLine(
                $inventory,
                $mapping['inventory_floating_detail_id'] ?? null,
                0,
                $cogs,
                'خروج موجودی فروش ایونت #' . $saleId
            );
        }
        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'فروش ایونت #' . $saleId,
            $lines,
            'event-sale-' . $clientUuid
        );
        $this->reviews->ensureReview($voucherId, 'sales', (string) $saleId, 'فروش ایونت #' . $saleId, [
            'event_sale_id' => $saleId,
        ]);

        return $voucherId;
    }

    /** @return array<string, int|string|null> */
    private function journalLine(int $ledger, mixed $detail, int $debit, int $credit, string $description): array
    {
        $detailId = filter_var($detail, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'subsidiary_ledger_id' => $ledger,
            'floating_detail_id' => $detailId === false ? null : (int) $detailId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function eventRows(bool $sellerOnly): array
    {
        global $wpdb;
        $events = $wpdb->prefix . 'rishe_sales_events';
        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        $sellers = $wpdb->prefix . 'rishe_sales_event_sellers';
        $sales = $wpdb->prefix . 'rishe_event_sales';
        $args = [];
        $where = '1=1';
        if ($sellerOnly && !current_user_can('manage_rishe') && !current_user_can('rishe_manage_sales')) {
            $where = 'EXISTS (SELECT 1 FROM ' . $sellers . ' es WHERE es.event_id=e.id AND es.user_id=%d)';
            $args[] = get_current_user_id();
        }
        if ($sellerOnly) {
            $where .= " AND e.status='active'";
        }
        $sql = "SELECT e.*, w.name AS warehouse_name,
                       (SELECT COUNT(*) FROM {$sellers} es WHERE es.event_id=e.id) AS seller_count,
                       (SELECT COUNT(*) FROM {$sales} ss WHERE ss.event_id=e.id AND ss.status='synced') AS sales_count,
                       (SELECT COALESCE(SUM(total_irr),0) FROM {$sales} ss WHERE ss.event_id=e.id AND ss.status='synced') AS sales_total_irr
                FROM {$events} e INNER JOIN {$warehouses} w ON w.id=e.warehouse_id
                WHERE {$where} ORDER BY e.starts_at DESC,e.id DESC";
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([$this, 'formatEvent'], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed> */
    private function event(int $eventId): array
    {
        global $wpdb;
        $events = $wpdb->prefix . 'rishe_sales_events';
        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, w.name AS warehouse_name FROM {$events} e
             INNER JOIN {$warehouses} w ON w.id=e.warehouse_id WHERE e.id=%d",
            $eventId
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('ایونت پیدا نشد.');
        }
        $event = $this->formatEvent($row);
        $sellerRows = $wpdb->get_results($wpdb->prepare(
            "SELECT es.user_id,u.display_name FROM {$wpdb->prefix}rishe_sales_event_sellers es
             INNER JOIN {$wpdb->users} u ON u.ID=es.user_id WHERE es.event_id=%d ORDER BY u.display_name",
            $eventId
        ), ARRAY_A);
        $event['sellers'] = array_map(static fn (array $seller): array => [
            'id' => (int) $seller['user_id'],
            'name' => (string) $seller['display_name'],
        ], is_array($sellerRows) ? $sellerRows : []);

        return $event;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatEvent(array $row): array
    {
        foreach (['id', 'warehouse_id', 'created_by', 'seller_count', 'sales_count', 'sales_total_irr'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatSale(array $row): array
    {
        foreach ([
            'id', 'event_id', 'seller_user_id', 'wc_order_id', 'rishe_order_id', 'subtotal_irr', 'discount_irr',
            'total_irr', 'paid_irr', 'cogs_irr', 'accounting_voucher_id',
        ] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function existingSale(string $clientUuid): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*,e.name AS event_name,u.display_name AS seller_name
             FROM {$wpdb->prefix}rishe_event_sales s
             INNER JOIN {$wpdb->prefix}rishe_sales_events e ON e.id=s.event_id
             LEFT JOIN {$wpdb->users} u ON u.ID=s.seller_user_id
             WHERE s.client_uuid=%s",
            $clientUuid
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function replaceSellers(int $eventId, mixed $sellerIds): void
    {
        global $wpdb;
        if (!is_array($sellerIds)) {
            throw new RuntimeException('فهرست فروشندگان معتبر نیست.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $sellerIds), static fn (int $id): bool => $id > 0)));
        $wpdb->delete($wpdb->prefix . 'rishe_sales_event_sellers', ['event_id' => $eventId], ['%d']);
        foreach ($ids as $userId) {
            if (get_user_by('id', $userId) === false) {
                continue;
            }
            $inserted = $wpdb->insert($wpdb->prefix . 'rishe_sales_event_sellers', [
                'event_id' => $eventId,
                'user_id' => $userId,
                'assigned_by' => get_current_user_id(),
                'created_at' => current_time('mysql', true),
            ], ['%d', '%d', '%d', '%s']);
            if ($inserted === false) {
                throw new RuntimeException('تخصیص فروشنده به ایونت انجام نشد.');
            }
        }
    }

    private function assertSellerAccess(int $eventId): void
    {
        if (current_user_can('manage_rishe') || current_user_can('rishe_manage_sales')) {
            return;
        }
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rishe_sales_event_sellers WHERE event_id=%d AND user_id=%d",
            $eventId,
            get_current_user_id()
        ));
        if ($exists !== 1) {
            throw new RuntimeException('این ایونت به شما تخصیص داده نشده است.');
        }
    }

    private function assertWarehouse(int $warehouseId): void
    {
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rishe_warehouses WHERE id=%d AND is_active=1",
            $warehouseId
        ));
        if ($exists !== 1) {
            throw new RuntimeException('انبار ایونت معتبر نیست.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();

        return is_array($payload) ? $payload : [];
    }

    private function eventStatus(mixed $value): string
    {
        $status = sanitize_key((string) $value);
        if (!in_array($status, ['draft', 'active', 'closed', 'cancelled'], true)) {
            throw new RuntimeException('وضعیت ایونت معتبر نیست.');
        }

        return $status;
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException($label . ' معتبر نیست.');
        }

        return (int) $id;
    }

    private function money(mixed $value, string $label, int $minimum): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        if ($amount === false) {
            throw new RuntimeException($label . ' معتبر نیست.');
        }

        return (int) $amount;
    }

    private function requiredText(mixed $value, string $label, int $maximum): string
    {
        $text = trim(wp_strip_all_tags((string) $value));
        if ($text === '' || mb_strlen($text) > $maximum) {
            throw new RuntimeException($label . ' الزامی است.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        $text = trim(wp_strip_all_tags((string) $value));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maximum) {
            throw new RuntimeException('متن واردشده بیش از حد طولانی است.');
        }

        return $text;
    }

    private function dateTime(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        $text = str_replace('T', ' ', $text);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text) === 1) {
            $text .= ':00';
        }
        $timestamp = strtotime($text);
        if ($timestamp === false || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) !== 1) {
            throw new RuntimeException($label . ' معتبر نیست.');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function storeMoneyToIrr(string $value): int
    {
        $currency = strtoupper((string) get_woocommerce_currency());
        $amount = (float) $value;

        return (int) round(in_array($currency, ['IRT', 'TMN', 'TOMAN'], true) ? $amount * 10 : $amount);
    }

    private function irrToStoreMoney(int $amount): float
    {
        $currency = strtoupper((string) get_woocommerce_currency());
        if (!in_array($currency, ['IRR', 'IRT', 'TMN', 'TOMAN'], true)) {
            throw new RuntimeException('واحد پول ووکامرس باید ریال یا تومان باشد.');
        }

        return in_array($currency, ['IRT', 'TMN', 'TOMAN'], true) ? $amount / 10 : (float) $amount;
    }

    private function decimalQuantity(int $scaled): string
    {
        $value = number_format($scaled / 10000, 4, '.', '');

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function normalizeMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : '';
    }

    private function recordSyncFailure(string $clientUuid, string $message): void
    {
        if (!wp_is_uuid($clientUuid)) {
            return;
        }
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'rishe_event_sales', [
            'status' => 'sync_failed',
            'error_message' => mb_substr($message, 0, 1000),
            'updated_at' => current_time('mysql', true),
        ], ['client_uuid' => $clientUuid], ['%s', '%s', '%s'], ['%s']);
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_event_sales_error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            error_log('[Rishe event sales] ' . $exception->getMessage());

            return new WP_REST_Response([
                'code' => 'rishe_event_sales_unexpected',
                'message' => 'خطای غیرمنتظره در فروش ایونت رخ داد.',
            ], 500);
        }
    }
}
