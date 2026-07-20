<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Application;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Sales\Application\SalesService;
use Rishe\Sales\Domain\MobileNormalizer;
use Rishe\Sales\Domain\OrderTotalCalculator;
use Rishe\Sales\Infrastructure\WooCommerceOrderMapper;
use Rishe\Sales\Infrastructure\WpAccountingGateway;
use Rishe\Sales\Infrastructure\WpInventoryGateway;
use Rishe\Sales\Infrastructure\WpdbSalesRepository;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;

final class WooCommerceSyncService
{
    use WooCommerceProductSync;
    use WooCommerceOrderSync;

    public const OPTION = 'rishe_woocommerce_sync';
    public const CRON_HOOK = 'rishe/woocommerce/reconcile';
    public const ASYNC_STOCK_HOOK = 'rishe/woocommerce/sync_stock';

    private InventoryService $inventory;
    private AccountingService $accounting;
    private SalesService $sales;
    private WooCommerceOrderMapper $orderMapper;
    private AuditLogger $audit;

    private static bool $pushing = false;
    private static bool $pulling = false;
    private static bool $syncingOrder = false;

    public function __construct()
    {
        $tx = new TransactionManager();
        $this->audit = new AuditLogger();
        $this->inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            $tx,
            $this->audit
        );
        $salesRepository = new WpdbSalesRepository();
        $this->accounting = new AccountingService(new WpdbAccountingRepository(), $tx, $this->audit);
        $this->sales = new SalesService(
            $salesRepository,
            new WpInventoryGateway($this->inventory),
            new WpAccountingGateway($this->accounting),
            $tx,
            $this->audit,
            new MobileNormalizer(),
            new OrderTotalCalculator()
        );
        $this->orderMapper = new WooCommerceOrderMapper($salesRepository);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $value = get_option(self::OPTION, []);
        $value = is_array($value) ? $value : [];

        return wp_parse_args($value, [
            'enabled' => false,
            'warehouse_id' => (int) get_option('rishe_woocommerce_warehouse_id', 0),
            'sync_orders' => true,
            'sync_refunds' => true,
            'sync_stock' => true,
            'auto_map_products' => true,
            'pull_manual_wc_stock' => true,
            'reconcile_source' => 'rishe',
            'reconcile_interval' => 'hourly',
            'default_unit_cost_irr' => 0,
            'started_at' => null,
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveSettings(array $input): array
    {
        $current = $this->settings();
        $enabled = $this->asBool($input['enabled'] ?? $current['enabled']);
        $warehouseId = (int) ($input['warehouse_id'] ?? $current['warehouse_id']);
        if ($enabled && !$this->warehouseExists($warehouseId)) {
            throw new RuntimeException('برای فعال‌سازی اتصال، یک انبار معتبر ریشه انتخاب کنید.');
        }
        $source = strtolower(trim((string) ($input['reconcile_source'] ?? $current['reconcile_source'])));
        $source = in_array($source, ['rishe', 'woocommerce'], true) ? $source : 'rishe';
        $interval = strtolower(trim((string) ($input['reconcile_interval'] ?? $current['reconcile_interval'])));
        $interval = in_array($interval, ['fifteen_minutes', 'hourly', 'twicedaily', 'daily'], true)
            ? $interval
            : 'hourly';

        $settings = [
            'enabled' => $enabled,
            'warehouse_id' => $warehouseId,
            'sync_orders' => $this->asBool($input['sync_orders'] ?? $current['sync_orders']),
            'sync_refunds' => $this->asBool($input['sync_refunds'] ?? $current['sync_refunds']),
            'sync_stock' => $this->asBool($input['sync_stock'] ?? $current['sync_stock']),
            'auto_map_products' => $this->asBool($input['auto_map_products'] ?? $current['auto_map_products']),
            'pull_manual_wc_stock' => $this->asBool($input['pull_manual_wc_stock'] ?? $current['pull_manual_wc_stock']),
            'reconcile_source' => $source,
            'reconcile_interval' => $interval,
            'default_unit_cost_irr' => max(0, (int) ($input['default_unit_cost_irr'] ?? $current['default_unit_cost_irr'])),
            'started_at' => $enabled
                ? (((string) ($current['started_at'] ?? '')) !== '' ? $current['started_at'] : gmdate('c'))
                : $current['started_at'],
        ];
        update_option(self::OPTION, $settings, false);
        update_option('rishe_woocommerce_warehouse_id', $warehouseId, false);
        $this->reschedule($settings);
        $this->audit->record('woocommerce.settings.updated', 'woocommerce_sync', 'settings', [
            'enabled' => $enabled,
            'warehouse_id' => $warehouseId,
            'reconcile_source' => $source,
        ]);

        return $settings;
    }

    public function enabled(): bool
    {
        return class_exists('WooCommerce') && (bool) $this->settings()['enabled'];
    }

    public function ownsStock(): bool
    {
        $settings = $this->settings();

        return $this->enabled() && (bool) $settings['sync_stock'];
    }

    public static function isPushing(): bool
    {
        return self::$pushing;
    }

    public static function isPulling(): bool
    {
        return self::$pulling;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        global $wpdb;
        $settings = $this->settings();
        $products = $wpdb->prefix . 'rishe_products';
        $mapped = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products} WHERE is_active=1 AND wc_product_id IS NOT NULL");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products} WHERE is_active=1");
        $mismatches = 0;
        if (class_exists('WooCommerce') && $this->warehouseExists((int) $settings['warehouse_id'])) {
            foreach ($this->mappedRows() as $row) {
                $wc = wc_get_product((int) $row['wc_product_id']);
                if (!$wc || !$wc->managing_stock()) {
                    continue;
                }
                if (abs((float) ($wc->get_stock_quantity() ?? 0) - $this->available((int) $row['id'], (int) $settings['warehouse_id'])) > 0.0001) {
                    $mismatches++;
                }
            }
        }

        return [
            'enabled' => (bool) $settings['enabled'],
            'woocommerce_active' => class_exists('WooCommerce'),
            'warehouse_id' => (int) $settings['warehouse_id'],
            'mapped_products' => $mapped,
            'unmapped_rishe_products' => max(0, $total - $mapped),
            'stock_mismatches' => $mismatches,
            'last_run' => get_option('rishe_woocommerce_last_run', null),
            'last_error' => get_option('rishe_woocommerce_last_error', null),
            'next_scheduled_run' => wp_next_scheduled(self::CRON_HOOK) ?: null,
            'settings' => $settings,
        ];
    }

    /** @return array{processed:int,changed:int,skipped:int,errors:list<string>} */
    public function reconcile(): array
    {
        $result = (string) $this->settings()['reconcile_source'] === 'woocommerce'
            ? $this->pullAll()
            : $this->pushAll();
        $this->rememberRun('reconcile', $result);

        return $result;
    }

    public function shouldOwnOrderStock(object $order): bool
    {
        if (!$this->ownsStock()) {
            return false;
        }
        $settings = $this->settings();
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) {
                return false;
            }
            $mapped = $this->byWooId((int) $product->get_id());
            if ($mapped === null && (bool) $settings['auto_map_products']) {
                try {
                    $mapped = $this->ensureMapping($product);
                } catch (Throwable) {
                    return false;
                }
            }
            if ($mapped === null) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $event @return list<array{product_id:int,warehouse_id:int}> */
    public function affectedByAudit(array $event): array
    {
        global $wpdb;
        $type = (string) ($event['event_type'] ?? '');
        $id = (string) ($event['aggregate_id'] ?? '');
        if ($id === '') {
            return [];
        }
        if ($type === 'inventory.stock.received') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT product_id,warehouse_id FROM {$wpdb->prefix}rishe_inventory_batches WHERE id=%d",
                (int) $id
            ), ARRAY_A);

            return is_array($row) ? [['product_id' => (int) $row['product_id'], 'warehouse_id' => (int) $row['warehouse_id']]] : [];
        }
        if (in_array($type, ['inventory.stock.reserved', 'inventory.reservation.released', 'inventory.reservation.committed'], true)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT product_id,warehouse_id FROM {$wpdb->prefix}rishe_stock_reservations WHERE id=%d",
                (int) $id
            ), ARRAY_A);

            return is_array($row) ? [['product_id' => (int) $row['product_id'], 'warehouse_id' => (int) $row['warehouse_id']]] : [];
        }
        if ($type === 'inventory.stock.transferred') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT product_id,warehouse_id FROM {$wpdb->prefix}rishe_stock_movements WHERE transfer_group_id=%s",
                $id
            ), ARRAY_A);

            return array_map(static fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'warehouse_id' => (int) $row['warehouse_id'],
            ], is_array($rows) ? $rows : []);
        }

        return [];
    }

    /** @param array<string, mixed> $settings */
    public function reschedule(array $settings): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if ((bool) $settings['enabled'] && (bool) $settings['sync_stock']) {
            wp_schedule_event(time() + 300, (string) $settings['reconcile_interval'], self::CRON_HOOK);
        }
    }

    private function warehouseExists(int $id): bool
    {
        global $wpdb;
        if ($id < 1) {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rishe_warehouses WHERE id=%d AND is_active=1",
            $id
        )) === 1;
    }

    private function assertWarehouse(int $id): void
    {
        if (!$this->warehouseExists($id)) {
            throw new RuntimeException('انبار پیش‌فرض اتصال ووکامرس معتبر نیست.');
        }
    }

    private function assertWooCommerce(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            throw new RuntimeException('ووکامرس فعال نیست.');
        }
    }

    private function actor(): int
    {
        return max(1, (int) get_option('rishe_system_user_id', 1));
    }

    private function decimal(float $value): string
    {
        $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $value === '' ? '0' : $value;
    }

    private function asBool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string, mixed> $result */
    private function rememberRun(string $operation, array $result): void
    {
        update_option('rishe_woocommerce_last_run', [
            'operation' => $operation,
            'result' => $result,
            'occurred_at' => gmdate('c'),
        ], false);
        delete_option('rishe_woocommerce_last_error');
    }

    /** @param array<string, mixed> $context */
    private function rememberError(string $operation, Throwable $e, array $context = []): void
    {
        update_option('rishe_woocommerce_last_error', [
            'operation' => $operation,
            'message' => $e->getMessage(),
            'context' => $context,
            'occurred_at' => gmdate('c'),
        ], false);
        error_log('[Rishe WooCommerce] ' . $operation . ': ' . $e->getMessage());
    }
}
