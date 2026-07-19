<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure;

use Rishe\Inventory\Application\InventoryRepository;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Domain\Quantity;
use RuntimeException;

final class WpdbInventoryRepository implements InventoryRepository
{
    private WpdbStockMutationGateway $mutations;

    public function __construct(FifoAllocator $allocator)
    {
        $this->mutations = new WpdbStockMutationGateway($allocator);
    }

    public function createWarehouse(array $data): int
    {
        return $this->insert('rishe_warehouses', [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'is_active' => 1,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%d', '%s', '%s'], 'warehouse');
    }

    public function createProduct(array $data): int
    {
        return $this->insert('rishe_products', [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'base_unit' => $data['base_unit'],
            'quantity_scale' => Quantity::SCALE,
            'inventory_method' => $data['inventory_method'],
            'wc_product_id' => $data['wc_product_id'],
            'is_active' => 1,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s'], 'product');
    }

    public function product(int $productId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_products';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $productId), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function receive(array $data): int
    {
        return $this->mutations->receive($data);
    }

    public function reserve(array $data): array
    {
        return $this->mutations->reserve($data);
    }

    public function releaseReservation(int $reservationId, int $actorUserId): array
    {
        return $this->mutations->releaseReservation($reservationId, $actorUserId);
    }

    public function commitReservation(int $reservationId, int $actorUserId): array
    {
        return $this->mutations->commitReservation($reservationId, $actorUserId);
    }

    public function transfer(array $data): array
    {
        return $this->mutations->transfer($data);
    }

    public function stockSummary(array $filters): array
    {
        global $wpdb;

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $products = $wpdb->prefix . 'rishe_products';
        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        [$where, $args] = $this->filters($filters, 'b');
        $sql = "SELECT b.product_id, p.sku, p.name AS product_name,
                       b.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
                       SUM(b.quantity_on_hand) AS on_hand_scaled,
                       SUM(b.quantity_reserved) AS reserved_scaled,
                       SUM(b.quantity_on_hand - b.quantity_reserved) AS available_scaled,
                       SUM((b.quantity_on_hand * b.unit_cost_irr) DIV " . Quantity::SCALE . ") AS inventory_value_irr
                FROM {$batches} b
                INNER JOIN {$products} p ON p.id = b.product_id
                INNER JOIN {$warehouses} w ON w.id = b.warehouse_id
                {$where}
                GROUP BY b.product_id, b.warehouse_id, p.sku, p.name, w.code, w.name
                ORDER BY p.sku, w.code";
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([$this, 'formatStock'], is_array($rows) ? $rows : []);
    }

    public function ledger(array $filters): array
    {
        global $wpdb;

        $movements = $wpdb->prefix . 'rishe_stock_movements';
        $products = $wpdb->prefix . 'rishe_products';
        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        [$where, $args] = $this->filters($filters, 'm', true);
        $sql = "SELECT m.*, p.sku, p.name AS product_name, w.code AS warehouse_code
                FROM {$movements} m
                INNER JOIN {$products} p ON p.id = m.product_id
                INNER JOIN {$warehouses} w ON w.id = m.warehouse_id
                {$where} ORDER BY m.id ASC";
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(static function (array $row): array {
            $row['quantity_scaled'] = (int) $row['quantity_scaled'];
            $row['quantity'] = Quantity::fromScaled(abs($row['quantity_scaled']), true)->decimal();
            $row['unit_cost_irr'] = (int) $row['unit_cost_irr'];

            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert(string $suffix, array $data, array $formats, string $entity): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . $suffix, $data, $formats);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create ' . $entity . ': ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $filters @return array{string, list<mixed>} */
    private function filters(array $filters, string $alias, bool $dates = false): array
    {
        $clauses = ['1=1'];
        $args = [];
        foreach (['product_id', 'warehouse_id'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $clauses[] = "{$alias}.{$field} = %d";
                $args[] = $filters[$field];
            }
        }
        if ($dates && ($filters['from'] ?? null) !== null) {
            $clauses[] = "{$alias}.created_at >= %s";
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if ($dates && ($filters['to'] ?? null) !== null) {
            $clauses[] = "{$alias}.created_at <= %s";
            $args[] = $filters['to'] . ' 23:59:59';
        }

        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatStock(array $row): array
    {
        foreach (['on_hand_scaled', 'reserved_scaled', 'available_scaled', 'inventory_value_irr'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['on_hand'] = Quantity::fromScaled($row['on_hand_scaled'], true)->decimal();
        $row['reserved'] = Quantity::fromScaled($row['reserved_scaled'], true)->decimal();
        $row['available'] = Quantity::fromScaled($row['available_scaled'], true)->decimal();

        return $row;
    }
}
