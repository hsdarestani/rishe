<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;

final class WpdbInitialCostService
{
    public function __construct(
        private readonly TransactionManager $transactions = new TransactionManager(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{updated:int, skipped:int, rows:list<array<string, mixed>>}
     */
    public function apply(array $items): array
    {
        if ($items === [] || count($items) > 500) {
            throw new InventoryDomainException('حداقل یک کالا و حداکثر ۵۰۰ ردیف را می‌توان ثبت کرد.');
        }

        return $this->transactions->run(function () use ($items): array {
            $updated = 0;
            $skipped = 0;
            $rows = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new InventoryDomainException('ساختار ردیف بهای اولیه معتبر نیست.');
                }

                $result = $this->applyOne($item);
                $rows[] = $result;
                if ((int) $result['affected_batches'] > 0) {
                    ++$updated;
                } else {
                    ++$skipped;
                }
            }

            return ['updated' => $updated, 'skipped' => $skipped, 'rows' => $rows];
        });
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function applyOne(array $item): array
    {
        global $wpdb;

        $productId = $this->positiveId($item['product_id'] ?? null, 'product_id');
        $warehouseId = $this->positiveId($item['warehouse_id'] ?? null, 'warehouse_id');
        $unitCost = $this->positiveMoney($item['unit_cost_irr'] ?? null);

        $this->assertActive($wpdb->prefix . 'rishe_products', $productId, 'کالا');
        $this->assertActive($wpdb->prefix . 'rishe_warehouses', $warehouseId, 'انبار');

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $batchRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, quantity_on_hand, unit_cost_irr FROM {$batches}
             WHERE product_id = %d AND warehouse_id = %d AND status = 'active'
             AND quantity_on_hand > 0 AND unit_cost_irr = 0
             ORDER BY received_at, id FOR UPDATE",
            $productId,
            $warehouseId
        ), ARRAY_A);
        $batchRows = is_array($batchRows) ? $batchRows : [];

        if ($batchRows === []) {
            return [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'unit_cost_irr' => $unitCost,
                'affected_batches' => 0,
                'quantity' => '0',
                'old_value_irr' => 0,
                'new_value_irr' => 0,
            ];
        }

        $batchIds = [];
        $quantityScaled = 0;
        foreach ($batchRows as $row) {
            $batchIds[] = (int) $row['id'];
            $quantityScaled += (int) $row['quantity_on_hand'];
        }

        $placeholders = implode(',', array_fill(0, count($batchIds), '%d'));
        $now = current_time('mysql', true);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$batches} SET unit_cost_irr = %d, updated_at = %s WHERE id IN ({$placeholders})",
            $unitCost,
            $now,
            ...$batchIds
        ));
        if ($updated === false) {
            throw new RuntimeException('ثبت بهای اولیه موجودی انجام نشد: ' . $wpdb->last_error);
        }

        $allocations = $wpdb->prefix . 'rishe_stock_reservation_allocations';
        $reservations = $wpdb->prefix . 'rishe_stock_reservations';
        $allocationUpdate = $wpdb->query($wpdb->prepare(
            "UPDATE {$allocations} a
             INNER JOIN {$reservations} r ON r.id = a.reservation_id
             SET a.unit_cost_irr = %d
             WHERE r.status = 'active' AND a.batch_id IN ({$placeholders})",
            $unitCost,
            ...$batchIds
        ));
        if ($allocationUpdate === false) {
            throw new RuntimeException('به‌روزرسانی بهای رزروهای فعال انجام نشد: ' . $wpdb->last_error);
        }

        $newValue = intdiv($quantityScaled * $unitCost, Quantity::SCALE);
        $this->audit->record(
            'inventory.initial_cost.updated',
            'inventory_valuation',
            $productId . ':' . $warehouseId,
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'unit_cost_irr' => $unitCost,
                'batch_ids' => $batchIds,
                'quantity_scaled' => $quantityScaled,
                'old_value_irr' => 0,
                'new_value_irr' => $newValue,
            ]
        );

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'unit_cost_irr' => $unitCost,
            'affected_batches' => count($batchIds),
            'quantity' => Quantity::fromScaled($quantityScaled, true)->decimal(),
            'old_value_irr' => 0,
            'new_value_irr' => $newValue,
        ];
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InventoryDomainException($field . ' باید یک شناسه معتبر باشد.');
        }

        return (int) $id;
    }

    private function positiveMoney(mixed $value): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new InventoryDomainException('بهای خرید باید مبلغی بزرگ‌تر از صفر و به ریال باشد.');
        }

        return (int) $amount;
    }

    private function assertActive(string $table, int $id, string $label): void
    {
        global $wpdb;

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d AND is_active = 1",
            $id
        ));
        if ($exists !== 1) {
            throw new InventoryDomainException($label . ' انتخاب‌شده معتبر یا فعال نیست.');
        }
    }
}
