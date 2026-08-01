<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class BusinessRestApi
{
    private AccountingService $accounting;

    public function __construct(?AccountingService $accounting = null)
    {
        $this->accounting = $accounting ?? new AccountingService(
            new WpdbAccountingRepository(),
            new TransactionManager(),
            new AuditLogger()
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $view = static fn (): bool => current_user_can('rishe_view_reports') || current_user_can('manage_rishe');
        $finance = static fn (): bool => current_user_can('rishe_manage_accounting') || current_user_can('manage_rishe');

        register_rest_route('rishe/v1', '/business/overview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'overview'],
            'permission_callback' => $view,
        ]);
        register_rest_route('rishe/v1', '/business/catalog', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'catalog'],
            'permission_callback' => $view,
        ]);
        register_rest_route('rishe/v1', '/business/woocommerce-orders', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'woocommerceOrders'],
            'permission_callback' => $view,
        ]);
        register_rest_route('rishe/v1', '/business/finance/summary', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'financeSummary'],
            'permission_callback' => $view,
        ]);
        register_rest_route('rishe/v1', '/business/finance/opening-balance', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'openingBalance'],
            'permission_callback' => $finance,
        ]);
        register_rest_route('rishe/v1', '/business/preferences', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'savePreferences'],
            'permission_callback' => static fn (): bool => current_user_can('rishe_manage_settings') || current_user_can('manage_rishe'),
        ]);
    }

    public function overview(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(function (): array {
            $woocommerce = $this->woocommerceOverview();
            $lowStock = $this->lowStockCount();
            $pendingPurchases = $this->countByStatuses('rishe_purchase_orders', ['draft', 'approved', 'partially_received']);
            $pendingShipments = $this->countByStatuses('rishe_shipments', ['draft', 'quoted', 'booked', 'in_transit']);
            $pendingB2b = $this->countByStatuses('rishe_consignment_dispatches', ['draft', 'dispatched', 'partially_returned']);
            $openAlerts = $this->countByStatuses('rishe_analytics_alerts', ['open']);

            $tasks = [];
            if ($woocommerce['pending_orders'] > 0) {
                $tasks[] = [
                    'type' => 'sales',
                    'title' => $woocommerce['pending_orders'] . ' سفارش نیازمند پیگیری',
                    'description' => 'سفارش‌های ووکامرس که هنوز تکمیل یا ارسال نشده‌اند.',
                    'page' => 'sales',
                    'priority' => 'high',
                ];
            }
            if ($lowStock > 0) {
                $tasks[] = [
                    'type' => 'inventory',
                    'title' => $lowStock . ' کالا کم‌موجود است',
                    'description' => 'موجودی قابل‌فروش این کالاها به آستانه هشدار رسیده است.',
                    'page' => 'inventory',
                    'priority' => 'high',
                ];
            }
            if ($pendingPurchases > 0) {
                $tasks[] = [
                    'type' => 'procurement',
                    'title' => $pendingPurchases . ' خرید باز',
                    'description' => 'سفارش خریدی که دریافت یا تسویه کامل نشده است.',
                    'page' => 'procurement',
                    'priority' => 'medium',
                ];
            }
            if ($pendingShipments > 0) {
                $tasks[] = [
                    'type' => 'logistics',
                    'title' => $pendingShipments . ' ارسال در جریان',
                    'description' => 'مرسوله‌هایی که هنوز تحویل نهایی نشده‌اند.',
                    'page' => 'logistics',
                    'priority' => 'medium',
                ];
            }
            if ($pendingB2b > 0) {
                $tasks[] = [
                    'type' => 'b2b',
                    'title' => $pendingB2b . ' پرونده B2B باز',
                    'description' => 'ارسال یا تسویه‌ای که نیازمند پیگیری است.',
                    'page' => 'b2b',
                    'priority' => 'medium',
                ];
            }
            if ($openAlerts > 0) {
                $tasks[] = [
                    'type' => 'finance',
                    'title' => $openAlerts . ' هشدار مدیریتی باز',
                    'description' => 'هشدارهایی که هنوز بررسی یا تأیید نشده‌اند.',
                    'page' => 'finance',
                    'priority' => 'low',
                ];
            }

            return [
                'woocommerce' => $woocommerce,
                'kpis' => [
                    'month_sales_irr' => $woocommerce['month_sales_irr'],
                    'month_orders' => $woocommerce['month_orders'],
                    'customers' => $woocommerce['customers'],
                    'low_stock' => $lowStock,
                    'pending_purchases' => $pendingPurchases,
                    'pending_shipments' => $pendingShipments,
                ],
                'tasks' => $tasks,
                'channels' => $woocommerce['channels'],
                'preferences' => $this->preferences(),
            ];
        });
    }

    public function catalog(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(function (): array {
            global $wpdb;

            $products = [];
            $productTable = $wpdb->prefix . 'rishe_products';
            if ($this->tableExists($productTable)) {
                $rows = $wpdb->get_results(
                    "SELECT id, wc_product_id, sku, name, base_unit, is_active FROM {$productTable} WHERE is_active=1 ORDER BY name",
                    ARRAY_A
                );
                $products = array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'wc_product_id' => (int) ($row['wc_product_id'] ?? 0),
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'unit' => (string) ($row['base_unit'] ?? ''),
                ], is_array($rows) ? $rows : []);
            }

            if ($products === [] && function_exists('wc_get_products')) {
                $wooProducts = wc_get_products([
                    'limit' => 200,
                    'status' => ['publish', 'private'],
                    'orderby' => 'name',
                    'order' => 'ASC',
                ]);
                foreach ($wooProducts as $product) {
                    if (!is_object($product) || !method_exists($product, 'get_id')) {
                        continue;
                    }
                    $products[] = [
                        'id' => 0,
                        'wc_product_id' => (int) $product->get_id(),
                        'sku' => (string) $product->get_sku(),
                        'name' => (string) $product->get_name(),
                        'unit' => 'عدد',
                    ];
                }
            }

            $warehouses = [];
            $warehouseTable = $wpdb->prefix . 'rishe_warehouses';
            if ($this->tableExists($warehouseTable)) {
                $rows = $wpdb->get_results(
                    "SELECT id, code, name, type FROM {$warehouseTable} WHERE is_active=1 ORDER BY name",
                    ARRAY_A
                );
                $warehouses = array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                ], is_array($rows) ? $rows : []);
            }

            return ['products' => $products, 'warehouses' => $warehouses];
        });
    }

    public function woocommerceOrders(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            if (!function_exists('wc_get_orders')) {
                return ['active' => false, 'rows' => [], 'total' => 0];
            }

            $limit = max(1, min(100, (int) ($request->get_param('limit') ?: 30)));
            $status = sanitize_key((string) $request->get_param('status'));
            $args = [
                'limit' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'objects',
            ];
            if ($status !== '') {
                $args['status'] = $status;
            }
            $orders = wc_get_orders($args);
            $rows = [];
            foreach (is_array($orders) ? $orders : [] as $order) {
                if (!is_object($order) || !method_exists($order, 'get_id')) {
                    continue;
                }
                $created = $order->get_date_created();
                $channel = (string) $order->get_meta('_rishe_sales_channel', true);
                if ($channel === '') {
                    $channel = (string) $order->get_created_via();
                }
                $rows[] = [
                    'id' => (int) $order->get_id(),
                    'number' => (string) $order->get_order_number(),
                    'status' => (string) $order->get_status(),
                    'status_label' => function_exists('wc_get_order_status_name')
                        ? (string) wc_get_order_status_name($order->get_status())
                        : (string) $order->get_status(),
                    'customer' => trim((string) $order->get_formatted_billing_full_name()) ?: 'مشتری مهمان',
                    'phone' => (string) $order->get_billing_phone(),
                    'total_irr' => (int) round((float) $order->get_total()),
                    'items' => (int) $order->get_item_count(),
                    'payment_method' => (string) $order->get_payment_method_title(),
                    'channel' => $channel !== '' ? $channel : 'website',
                    'created_at' => $created ? $created->date('Y-m-d H:i:s') : '',
                    'edit_url' => method_exists($order, 'get_edit_order_url') ? (string) $order->get_edit_order_url() : '',
                ];
            }

            return ['active' => true, 'rows' => $rows, 'total' => count($rows)];
        });
    }

    public function financeSummary(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $from = sanitize_text_field((string) ($request->get_param('from') ?: gmdate('Y-01-01')));
            $to = sanitize_text_field((string) ($request->get_param('to') ?: gmdate('Y-m-d')));
            $rows = $this->accounting->trialBalance($from, $to);

            $totals = [
                'assets' => 0,
                'liabilities' => 0,
                'equity' => 0,
                'revenue' => 0,
                'expenses' => 0,
            ];
            foreach ($rows as $row) {
                $name = (string) (($row['group_name'] ?? '') . ' ' . ($row['general_name'] ?? ''));
                $code = (string) ($row['group_code'] ?? '');
                $debit = (int) ($row['debit_balance'] ?? 0);
                $credit = (int) ($row['credit_balance'] ?? 0);
                $balance = max($debit, $credit);

                if ($this->containsAny($name, ['دارایی', 'موجودی', 'بانک', 'صندوق', 'دریافتنی']) || str_starts_with($code, '1')) {
                    $totals['assets'] += $balance;
                } elseif ($this->containsAny($name, ['بدهی', 'پرداختنی', 'تعهد']) || str_starts_with($code, '2')) {
                    $totals['liabilities'] += $balance;
                } elseif ($this->containsAny($name, ['سرمایه', 'حقوق مالکانه', 'اندوخته']) || str_starts_with($code, '3')) {
                    $totals['equity'] += $balance;
                } elseif ($this->containsAny($name, ['درآمد', 'فروش']) || str_starts_with($code, '4')) {
                    $totals['revenue'] += $credit > 0 ? $credit : $balance;
                } elseif ($this->containsAny($name, ['هزینه', 'بهای تمام شده', 'بهای تمام‌شده']) || str_starts_with($code, '5') || str_starts_with($code, '6')) {
                    $totals['expenses'] += $debit > 0 ? $debit : $balance;
                }
            }

            return [
                'from' => $from,
                'to' => $to,
                'trial_balance' => $rows,
                'income_statement' => [
                    'revenue_irr' => $totals['revenue'],
                    'expenses_irr' => $totals['expenses'],
                    'profit_irr' => $totals['revenue'] - $totals['expenses'],
                ],
                'balance_sheet' => [
                    'assets_irr' => $totals['assets'],
                    'liabilities_irr' => $totals['liabilities'],
                    'equity_irr' => $totals['equity'],
                    'difference_irr' => $totals['assets'] - $totals['liabilities'] - $totals['equity'],
                ],
                'cash_flow' => $this->cashFlow($from, $to),
                'equity_statement' => [
                    'closing_equity_irr' => $totals['equity'] + ($totals['revenue'] - $totals['expenses']),
                    'period_profit_irr' => $totals['revenue'] - $totals['expenses'],
                ],
                'temporary' => true,
            ];
        });
    }

    public function openingBalance(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $request->get_json_params();
            if (!is_array($payload)) {
                throw new RuntimeException('اطلاعات سند افتتاحیه معتبر نیست.');
            }
            $lines = $payload['lines'] ?? [];
            if (!is_array($lines) || count($lines) < 2) {
                throw new RuntimeException('سند افتتاحیه حداقل به دو ردیف نیاز دارد.');
            }

            $normalized = [];
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $normalized[] = [
                    'subsidiary_ledger_id' => (int) ($line['subsidiary_ledger_id'] ?? 0),
                    'floating_detail_id' => !empty($line['floating_detail_id']) ? (int) $line['floating_detail_id'] : null,
                    'debit' => max(0, (int) ($line['debit_toman'] ?? 0)) * 10,
                    'credit' => max(0, (int) ($line['credit_toman'] ?? 0)) * 10,
                    'description' => sanitize_text_field((string) ($line['description'] ?? 'مانده افتتاحیه')),
                ];
            }

            $id = $this->accounting->createDraftVoucher(
                (int) ($payload['fiscal_year'] ?? 1405),
                sanitize_text_field((string) ($payload['voucher_date'] ?? gmdate('Y-m-d'))),
                sanitize_text_field((string) ($payload['description'] ?? 'سند افتتاحیه ریشه')),
                $normalized,
                'opening-' . wp_generate_uuid4()
            );

            $status = 'draft';
            $number = null;
            if (!empty($payload['post_immediately'])) {
                $number = $this->accounting->postVoucher($id, get_current_user_id());
                $status = 'posted';
            }

            return ['id' => $id, 'status' => $status, 'voucher_number' => $number];
        }, 201);
    }

    public function savePreferences(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $request->get_json_params();
            if (!is_array($payload)) {
                throw new RuntimeException('تنظیمات معتبر نیست.');
            }
            $allowed = ['monthly', 'weekly', 'daily'];
            $cadence = sanitize_key((string) ($payload['bank_reconciliation_cadence'] ?? 'monthly'));
            if (!in_array($cadence, $allowed, true)) {
                $cadence = 'monthly';
            }
            $preferences = [
                'bank_reconciliation_cadence' => $cadence,
                'woocommerce_is_sales_source' => true,
                'inventory_source' => 'rishe',
            ];
            update_option('rishe_business_preferences', $preferences, false);

            return $preferences;
        });
    }

    /** @return array<string, mixed> */
    private function woocommerceOverview(): array
    {
        if (!function_exists('wc_get_orders')) {
            return [
                'active' => false,
                'month_sales_irr' => 0,
                'month_orders' => 0,
                'pending_orders' => 0,
                'customers' => 0,
                'channels' => [],
            ];
        }

        $start = gmdate('Y-m-01 00:00:00');
        $orders = wc_get_orders([
            'limit' => -1,
            'date_created' => '>=' . $start,
            'status' => array_keys(wc_get_order_statuses()),
            'return' => 'objects',
        ]);
        $sales = 0;
        $pending = 0;
        $customers = [];
        $channels = [];
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!is_object($order)) {
                continue;
            }
            $status = (string) $order->get_status();
            if (!in_array($status, ['cancelled', 'failed', 'refunded', 'trash'], true)) {
                $sales += (int) round((float) $order->get_total());
            }
            if (in_array($status, ['pending', 'on-hold', 'processing'], true)) {
                ++$pending;
            }
            $customerKey = (string) ($order->get_customer_id() ?: $order->get_billing_phone() ?: $order->get_billing_email());
            if ($customerKey !== '') {
                $customers[$customerKey] = true;
            }
            $channel = (string) $order->get_meta('_rishe_sales_channel', true);
            if ($channel === '') {
                $channel = (string) $order->get_created_via();
            }
            $channel = $channel !== '' ? $channel : 'website';
            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
        }

        arsort($channels);
        return [
            'active' => true,
            'month_sales_irr' => $sales,
            'month_orders' => is_array($orders) ? count($orders) : 0,
            'pending_orders' => $pending,
            'customers' => count($customers),
            'channels' => array_map(
                static fn (string $name, int $count): array => ['name' => $name, 'orders' => $count],
                array_keys($channels),
                array_values($channels)
            ),
        ];
    }

    private function lowStockCount(): int
    {
        if (!function_exists('wc_get_products')) {
            return 0;
        }
        $products = wc_get_products([
            'limit' => -1,
            'status' => ['publish', 'private'],
            'stock_status' => 'instock',
        ]);
        $count = 0;
        foreach ($products as $product) {
            if (!is_object($product) || !$product->managing_stock()) {
                continue;
            }
            $stock = $product->get_stock_quantity();
            $threshold = function_exists('wc_get_low_stock_amount') ? wc_get_low_stock_amount($product) : 2;
            if ($stock !== null && $stock <= $threshold) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<string> $statuses */
    private function countByStatuses(string $suffix, array $statuses): int
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        if (!$this->tableExists($table) || $statuses === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $query = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})", ...$statuses);

        return max(0, (int) $wpdb->get_var($query));
    }

    /** @return array{inflow_irr:int,outflow_irr:int,net_irr:int} */
    private function cashFlow(string $from, string $to): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_treasury_transactions';
        if (!$this->tableExists($table)) {
            return ['inflow_irr' => 0, 'outflow_irr' => 0, 'net_irr' => 0];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT direction, SUM(amount_irr) amount FROM {$table} WHERE transaction_at BETWEEN %s AND %s GROUP BY direction",
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ), ARRAY_A);
        $inflow = 0;
        $outflow = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $amount = (int) ($row['amount'] ?? 0);
            if (in_array((string) ($row['direction'] ?? ''), ['in', 'credit', 'incoming'], true)) {
                $inflow += $amount;
            } else {
                $outflow += $amount;
            }
        }

        return ['inflow_irr' => $inflow, 'outflow_irr' => $outflow, 'net_irr' => $inflow - $outflow];
    }

    /** @return array<string, mixed> */
    private function preferences(): array
    {
        $value = get_option('rishe_business_preferences', []);
        $value = is_array($value) ? $value : [];

        return array_merge([
            'bank_reconciliation_cadence' => 'monthly',
            'woocommerce_is_sales_source' => true,
            'inventory_source' => 'rishe',
        ], $value);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            do_action('rishe/business/error', $exception);

            return new WP_REST_Response([
                'error' => 'عملیات ریشه با خطا روبه‌رو شد.',
                'technical' => current_user_can('manage_options') ? $exception->getMessage() : null,
            ], 500);
        }
    }
}
