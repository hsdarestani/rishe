<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure;

use Rishe\Inventory\Application\InventoryCompletionRepository;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use RuntimeException;

final class WpdbInventoryCompletionRepository implements InventoryCompletionRepository
{
    public function expiredReservationIds(
        int $limit,
        string $now,
        ?int $productId = null,
        ?int $warehouseId = null
    ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_stock_reservations';
        $clauses = ["status = 'active'", 'expires_at IS NOT NULL', 'expires_at <= %s'];
        $args = [$now];
        if ($productId !== null) {
            $clauses[] = 'product_id = %d';
            $args[] = $productId;
        }
        if ($warehouseId !== null) {
            $clauses[] = 'warehouse_id = %d';
            $args[] = $warehouseId;
        }
        $args[] = $limit;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY expires_at, id LIMIT %d',
            ...$args
        ));

        return array_map('intval', is_array($rows) ? $rows : []);
    }

    public function updateAllocationMethod(int $productId, string $method, int $actorUserId): array
    {
        global $wpdb;
        unset($actorUserId);

        $table = $wpdb->prefix . 'rishe_products';
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, inventory_method, is_active FROM {$table} WHERE id = %d FOR UPDATE",
            $productId
        ), ARRAY_A);
        if (!is_array($product) || (int) $product['is_active'] !== 1) {
            throw new InventoryDomainException('Product is missing or inactive.');
        }
        $previous = (string) $product['inventory_method'];
        if ($previous === $method) {
            return [
                'product_id' => $productId,
                'previous_method' => $previous,
                'allocation_method' => $method,
                'idempotent' => true,
            ];
        }

        $updated = $wpdb->update(
            $table,
            ['inventory_method' => $method, 'updated_at' => current_time('mysql', true)],
            ['id' => $productId, 'inventory_method' => $previous],
            ['%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update product allocation method.');
        }

        return [
            'product_id' => $productId,
            'previous_method' => $previous,
            'allocation_method' => $method,
            'idempotent' => false,
        ];
    }
}
