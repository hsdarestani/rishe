<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Infrastructure;

use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Manufacturing\Application\ManufacturingRepository;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;
use Rishe\Manufacturing\Domain\ProductionCostCalculator;
use RuntimeException;

final class WpdbManufacturingRepository implements ManufacturingRepository
{
    public function __construct(
        private readonly FifoAllocator $allocator,
        private readonly ProductionCostCalculator $costs
    ) {
    }

    public function createBom(array $data): int
    {
        global $wpdb;

        $this->assertProductActive((int) $data['output_product_id']);
        foreach ($data['components'] as $component) {
            $this->assertProductActive((int) $component['product_id']);
        }

        $boms = $wpdb->prefix . 'rishe_boms';
        $version = $data['version'];
        if ($version === null) {
            $latest = $wpdb->get_row($wpdb->prepare(
                "SELECT id, version FROM {$boms} WHERE code = %s ORDER BY version DESC LIMIT 1 FOR UPDATE",
                $data['code']
            ), ARRAY_A);
            $version = is_array($latest) ? ((int) $latest['version'] + 1) : 1;
        }

        $now = current_time('mysql', true);
        $bomId = $this->insert('rishe_boms', [
            'code' => $data['code'],
            'name' => $data['name'],
            'version' => $version,
            'output_product_id' => $data['output_product_id'],
            'output_quantity_scaled' => $data['output_quantity_scaled'],
            'status' => 'draft',
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'],
            'created_by' => $data['actor_user_id'],
            'activated_by' => null,
            'activated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'], 'BOM');

        foreach ($data['components'] as $component) {
            $this->insert('rishe_bom_components', [
                'bom_id' => $bomId,
                'product_id' => $component['product_id'],
                'component_type' => $component['component_type'],
                'quantity_scaled' => $component['quantity_scaled'],
                'waste_basis_points' => $component['waste_basis_points'],
                'sequence' => $component['sequence'],
                'created_at' => $now,
            ], ['%d', '%d', '%s', '%d', '%d', '%d', '%s'], 'BOM component');
        }

        return $bomId;
    }

    public function activateBom(int $bomId, int $actorUserId): array
    {
        global $wpdb;

        $boms = $wpdb->prefix . 'rishe_boms';
        $bom = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$boms} WHERE id = %d FOR UPDATE",
            $bomId
        ), ARRAY_A);
        if (!is_array($bom)) {
            throw new RuntimeException('BOM not found.');
        }
        if ((string) $bom['status'] !== 'draft') {
            throw new ManufacturingDomainException('Only a draft BOM can be activated.');
        }

        $this->assertProductActive((int) $bom['output_product_id']);
        $componentRows = $this->bomComponents($bomId, true);
        if ($componentRows === []) {
            throw new ManufacturingDomainException('A BOM must contain at least one component before activation.');
        }

        $activeIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$boms} WHERE code = %s AND status = 'active' AND id <> %d FOR UPDATE",
            $bom['code'],
            $bomId
        ));
        $retiredIds = array_map('intval', is_array($activeIds) ? $activeIds : []);
        if ($retiredIds !== []) {
            $placeholders = implode(',', array_fill(0, count($retiredIds), '%d'));
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$boms} SET status = 'retired', updated_at = %s WHERE id IN ({$placeholders})",
                current_time('mysql', true),
                ...$retiredIds
            ));
            if ($updated === false) {
                throw new RuntimeException('Unable to retire the previous active BOM version.');
            }
        }

        $now = current_time('mysql', true);
        $updated = $wpdb->update(
            $boms,
            [
                'status' => 'active',
                'activated_by' => $actorUserId,
                'activated_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $bomId, 'status' => 'draft'],
            ['%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to activate BOM.');
        }

        return [
            'code' => (string) $bom['code'],
            'version' => (int) $bom['version'],
            'retired_bom_ids' => $retiredIds,
        ];
    }

    public function executeProduction(array $data): array
    {
        global $wpdb;

        $existing = $this->findOrderByReference(
            (string) $data['reference_type'],
            (string) $data['reference_id'],
            true
        );
        if ($existing !== null) {
            if ((string) $existing['status'] !== 'completed') {
                throw new ManufacturingDomainException('Production reference is already in use by an incomplete order.');
            }

            return $this->orderResult((int) $existing['id'], true);
        }

        $bom = $this->activeBomForUpdate((int) $data['bom_id']);
        $this->assertWarehouseActive((int) $data['input_warehouse_id']);
        $this->assertWarehouseActive((int) $data['output_warehouse_id']);
        $this->assertProductActive((int) $bom['output_product_id']);
        $components = $this->bomComponents((int) $bom['id'], true);
        if ($components === []) {
            throw new ManufacturingDomainException('Active BOM has no components.');
        }

        $now = current_time('mysql', true);
        $orderId = $this->insert('rishe_production_orders', [
            'order_key' => wp_generate_uuid4(),
            'bom_id' => $bom['id'],
            'input_warehouse_id' => $data['input_warehouse_id'],
            'output_warehouse_id' => $data['output_warehouse_id'],
            'output_quantity_scaled' => $data['output_quantity_scaled'],
            'output_batch_code' => $data['output_batch_code'],
            'output_expiry_date' => $data['output_expiry_date'],
            'labor_cost_irr' => $data['labor_cost_irr'],
            'overhead_cost_irr' => $data['overhead_cost_irr'],
            'material_cost_irr' => null,
            'waste_cost_irr' => null,
            'total_cost_irr' => null,
            'unit_cost_irr' => null,
            'status' => 'processing',
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'completed_by' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s',
            '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s',
        ], 'production order');

        $materialCost = 0;
        $wasteCost = 0;
        foreach ($components as $component) {
            $requirement = $this->costs->requirement(
                (int) $component['quantity_scaled'],
                (int) $bom['output_quantity_scaled'],
                (int) $data['output_quantity_scaled'],
                (int) $component['waste_basis_points']
            );
            $allocations = $this->allocator->allocate(
                $this->lockBatches(
                    (int) $component['product_id'],
                    (int) $data['input_warehouse_id'],
                    (string) $component['inventory_method']
                ),
                $requirement['total_scaled']
            );
            $splits = $this->costs->splitAllocations($allocations, $requirement['standard_scaled']);

            foreach ($splits as $split) {
                $this->consumeBatch(
                    $orderId,
                    $component,
                    $split,
                    $data,
                    $materialCost,
                    $wasteCost,
                    $now
                );
            }
        }

        $totalCost = $this->sumCosts(
            $materialCost,
            $wasteCost,
            (int) $data['labor_cost_irr'],
            (int) $data['overhead_cost_irr']
        );
        $unitCost = $this->costs->unitCost($totalCost, (int) $data['output_quantity_scaled']);
        $outputBatchId = $this->createOutputBatch(
            $orderId,
            $bom,
            $data,
            $unitCost,
            $totalCost,
            $now
        );

        $orders = $wpdb->prefix . 'rishe_production_orders';
        $updated = $wpdb->update(
            $orders,
            [
                'material_cost_irr' => $materialCost,
                'waste_cost_irr' => $wasteCost,
                'total_cost_irr' => $totalCost,
                'unit_cost_irr' => $unitCost,
                'status' => 'completed',
                'completed_by' => $data['actor_user_id'],
                'completed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $orderId, 'status' => 'processing'],
            ['%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to complete production order.');
        }

        return [
            'id' => $orderId,
            'output_batch_id' => $outputBatchId,
            'output_quantity_scaled' => (int) $data['output_quantity_scaled'],
            'material_cost_irr' => $materialCost,
            'waste_cost_irr' => $wasteCost,
            'labor_cost_irr' => (int) $data['labor_cost_irr'],
            'overhead_cost_irr' => (int) $data['overhead_cost_irr'],
            'total_cost_irr' => $totalCost,
            'unit_cost_irr' => $unitCost,
            'idempotent' => false,
        ];
    }

    public function boms(array $filters): array
    {
        global $wpdb;

        $boms = $wpdb->prefix . 'rishe_boms';
        $products = $wpdb->prefix . 'rishe_products';
        $clauses = ['1=1'];
        $args = [];
        if (($filters['status'] ?? null) !== null) {
            $clauses[] = 'b.status = %s';
            $args[] = $filters['status'];
        }
        if (($filters['output_product_id'] ?? null) !== null) {
            $clauses[] = 'b.output_product_id = %d';
            $args[] = $filters['output_product_id'];
        }
        $sql = "SELECT b.*, p.sku AS output_sku, p.name AS output_product_name
                FROM {$boms} b INNER JOIN {$products} p ON p.id = b.output_product_id
                WHERE " . implode(' AND ', $clauses) . ' ORDER BY b.code, b.version DESC';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(function (array $row): array {
            $row = $this->formatBom($row);
            $row['components'] = $this->bomComponents((int) $row['id']);

            return $row;
        }, is_array($rows) ? $rows : []);
    }

    public function productionOrder(int $orderId): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_production_orders';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE id = %d",
            $orderId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        $row = $this->formatOrder($row);
        $row['consumptions'] = $this->orderConsumptions($orderId);
        $row['output'] = $this->orderOutput($orderId);

        return $row;
    }

    public function productionOrders(array $filters): array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_production_orders';
        $boms = $wpdb->prefix . 'rishe_boms';
        $clauses = ['1=1'];
        $args = [];
        if (($filters['bom_id'] ?? null) !== null) {
            $clauses[] = 'o.bom_id = %d';
            $args[] = $filters['bom_id'];
        }
        if (($filters['from'] ?? null) !== null) {
            $clauses[] = 'o.created_at >= %s';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? null) !== null) {
            $clauses[] = 'o.created_at <= %s';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        $sql = "SELECT o.*, b.code AS bom_code, b.version AS bom_version
                FROM {$orders} o INNER JOIN {$boms} b ON b.id = o.bom_id
                WHERE " . implode(' AND ', $clauses) . ' ORDER BY o.id DESC';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([$this, 'formatOrder'], is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $component @param array<string, mixed> $split @param array<string, mixed> $data */
    private function consumeBatch(
        int $orderId,
        array $component,
        array $split,
        array $data,
        int &$materialCost,
        int &$wasteCost,
        string $now
    ): void {
        global $wpdb;

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $quantity = (int) $split['total_scaled'];
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$batches}
             SET quantity_on_hand = quantity_on_hand - %d,
                 status = IF(quantity_on_hand - %d = 0, 'depleted', status), updated_at = %s
             WHERE id = %d AND status = 'active' AND (quantity_on_hand - quantity_reserved) >= %d",
            $quantity,
            $quantity,
            $now,
            $split['batch_id'],
            $quantity
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Unable to consume a locked production batch.');
        }

        $standardCost = $this->costs->extendedCost(
            (int) $split['standard_scaled'],
            (int) $split['unit_cost_irr']
        );
        $batchWasteCost = $this->costs->extendedCost(
            (int) $split['waste_scaled'],
            (int) $split['unit_cost_irr']
        );
        $materialCost = $this->checkedAdd($materialCost, $standardCost);
        $wasteCost = $this->checkedAdd($wasteCost, $batchWasteCost);

        $this->insert('rishe_production_consumptions', [
            'production_order_id' => $orderId,
            'bom_component_id' => $component['id'],
            'product_id' => $component['product_id'],
            'batch_id' => $split['batch_id'],
            'standard_quantity_scaled' => $split['standard_scaled'],
            'waste_quantity_scaled' => $split['waste_scaled'],
            'total_quantity_scaled' => $quantity,
            'unit_cost_irr' => $split['unit_cost_irr'],
            'material_cost_irr' => $standardCost,
            'waste_cost_irr' => $batchWasteCost,
            'created_at' => $now,
        ], ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s'], 'production consumption');

        if ((int) $split['standard_scaled'] > 0) {
            $this->movement([
                'type' => 'production_issue',
                'product_id' => $component['product_id'],
                'warehouse_id' => $data['input_warehouse_id'],
                'batch_id' => $split['batch_id'],
                'quantity_scaled' => -((int) $split['standard_scaled']),
                'unit_cost_irr' => $split['unit_cost_irr'],
                'reference_id' => (string) $orderId,
                'correlation_id' => $data['correlation_id'],
                'actor_user_id' => $data['actor_user_id'],
            ]);
        }
        if ((int) $split['waste_scaled'] > 0) {
            $this->movement([
                'type' => 'production_waste',
                'product_id' => $component['product_id'],
                'warehouse_id' => $data['input_warehouse_id'],
                'batch_id' => $split['batch_id'],
                'quantity_scaled' => -((int) $split['waste_scaled']),
                'unit_cost_irr' => $split['unit_cost_irr'],
                'reference_id' => (string) $orderId,
                'correlation_id' => $data['correlation_id'],
                'actor_user_id' => $data['actor_user_id'],
            ]);
        }
    }

    /** @param array<string, mixed> $bom @param array<string, mixed> $data */
    private function createOutputBatch(
        int $orderId,
        array $bom,
        array $data,
        int $unitCost,
        int $totalCost,
        string $now
    ): int {
        $batchId = $this->insert('rishe_inventory_batches', [
            'product_id' => $bom['output_product_id'],
            'warehouse_id' => $data['output_warehouse_id'],
            'batch_code' => $data['output_batch_code'],
            'origin_batch_id' => null,
            'received_at' => $now,
            'expiry_date' => $data['output_expiry_date'],
            'unit_cost_irr' => $unitCost,
            'quantity_on_hand' => $data['output_quantity_scaled'],
            'quantity_reserved' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s'], 'finished-goods batch');

        $this->insert('rishe_production_outputs', [
            'production_order_id' => $orderId,
            'product_id' => $bom['output_product_id'],
            'warehouse_id' => $data['output_warehouse_id'],
            'batch_id' => $batchId,
            'quantity_scaled' => $data['output_quantity_scaled'],
            'unit_cost_irr' => $unitCost,
            'total_cost_irr' => $totalCost,
            'created_at' => $now,
        ], ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s'], 'production output');

        $this->movement([
            'type' => 'production_receipt',
            'product_id' => $bom['output_product_id'],
            'warehouse_id' => $data['output_warehouse_id'],
            'batch_id' => $batchId,
            'quantity_scaled' => $data['output_quantity_scaled'],
            'unit_cost_irr' => $unitCost,
            'reference_id' => (string) $orderId,
            'correlation_id' => $data['correlation_id'],
            'actor_user_id' => $data['actor_user_id'],
        ]);

        return $batchId;
    }

    /** @return array<string, mixed> */
    private function activeBomForUpdate(int $bomId): array
    {
        global $wpdb;

        $boms = $wpdb->prefix . 'rishe_boms';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$boms} WHERE id = %d FOR UPDATE",
            $bomId
        ), ARRAY_A);
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            throw new ManufacturingDomainException('Production requires an active BOM.');
        }

        $today = current_time('Y-m-d');
        if ($row['effective_from'] !== null && (string) $row['effective_from'] > $today) {
            throw new ManufacturingDomainException('BOM is not effective yet.');
        }
        if ($row['effective_to'] !== null && (string) $row['effective_to'] < $today) {
            throw new ManufacturingDomainException('BOM is no longer effective.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function bomComponents(int $bomId, bool $requireActive = false): array
    {
        global $wpdb;

        $components = $wpdb->prefix . 'rishe_bom_components';
        $products = $wpdb->prefix . 'rishe_products';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.sku, p.name AS product_name, p.inventory_method, p.is_active
             FROM {$components} c INNER JOIN {$products} p ON p.id = c.product_id
             WHERE c.bom_id = %d ORDER BY c.sequence, c.id",
            $bomId
        ), ARRAY_A);
        $result = is_array($rows) ? $rows : [];
        foreach ($result as &$row) {
            if ($requireActive && !(bool) $row['is_active']) {
                throw new ManufacturingDomainException('BOM references an inactive component product.');
            }
            foreach (['id', 'bom_id', 'product_id', 'quantity_scaled', 'waste_basis_points', 'sequence'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['quantity'] = Quantity::fromScaled((int) $row['quantity_scaled'])->decimal();
            $row['is_active'] = (bool) $row['is_active'];
        }
        unset($row);

        return $result;
    }

    /** @return list<array{id: int, available_scaled: int, unit_cost_irr: int, batch_code: string}> */
    private function lockBatches(int $productId, int $warehouseId, string $method): array
    {
        global $wpdb;

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $direction = strtolower($method) === 'lifo' ? 'DESC' : 'ASC';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, batch_code, unit_cost_irr,
                    (quantity_on_hand - quantity_reserved) AS available_scaled
             FROM {$batches}
             WHERE product_id = %d AND warehouse_id = %d AND status = 'active'
               AND quantity_on_hand > quantity_reserved
               AND (expiry_date IS NULL OR expiry_date >= %s)
             ORDER BY received_at {$direction}, id {$direction} FOR UPDATE",
            $productId,
            $warehouseId,
            current_time('Y-m-d')
        ), ARRAY_A);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'available_scaled' => (int) $row['available_scaled'],
            'unit_cost_irr' => (int) $row['unit_cost_irr'],
            'batch_code' => (string) $row['batch_code'],
        ], is_array($rows) ? $rows : []);
    }

    private function assertProductActive(int $productId): void
    {
        global $wpdb;

        $products = $wpdb->prefix . 'rishe_products';
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$products} WHERE id = %d",
            $productId
        ));
        if ((int) $active !== 1) {
            throw new ManufacturingDomainException('Product is missing or inactive.');
        }
    }

    private function assertWarehouseActive(int $warehouseId): void
    {
        global $wpdb;

        $warehouses = $wpdb->prefix . 'rishe_warehouses';
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$warehouses} WHERE id = %d",
            $warehouseId
        ));
        if ((int) $active !== 1) {
            throw new ManufacturingDomainException('Warehouse is missing or inactive.');
        }
    }

    /** @return array<string, mixed>|null */
    private function findOrderByReference(string $referenceType, string $referenceId, bool $lock): ?array
    {
        global $wpdb;

        $orders = $wpdb->prefix . 'rishe_production_orders';
        $suffix = $lock ? ' FOR UPDATE' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orders} WHERE reference_type = %s AND reference_id = %s LIMIT 1{$suffix}",
            $referenceType,
            $referenceId
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function orderResult(int $orderId, bool $idempotent): array
    {
        $order = $this->productionOrder($orderId);
        if ($order === null || !is_array($order['output'] ?? null)) {
            throw new RuntimeException('Completed production order output could not be loaded.');
        }

        return [
            'id' => $orderId,
            'output_batch_id' => (int) $order['output']['batch_id'],
            'output_quantity_scaled' => (int) $order['output_quantity_scaled'],
            'material_cost_irr' => (int) $order['material_cost_irr'],
            'waste_cost_irr' => (int) $order['waste_cost_irr'],
            'labor_cost_irr' => (int) $order['labor_cost_irr'],
            'overhead_cost_irr' => (int) $order['overhead_cost_irr'],
            'total_cost_irr' => (int) $order['total_cost_irr'],
            'unit_cost_irr' => (int) $order['unit_cost_irr'],
            'idempotent' => $idempotent,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function orderConsumptions(int $orderId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_production_consumptions';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE production_order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            foreach ([
                'id', 'production_order_id', 'bom_component_id', 'product_id', 'batch_id',
                'standard_quantity_scaled', 'waste_quantity_scaled', 'total_quantity_scaled',
                'unit_cost_irr', 'material_cost_irr', 'waste_cost_irr',
            ] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['standard_quantity'] = Quantity::fromScaled($row['standard_quantity_scaled'], true)->decimal();
            $row['waste_quantity'] = Quantity::fromScaled($row['waste_quantity_scaled'], true)->decimal();
            $row['total_quantity'] = Quantity::fromScaled($row['total_quantity_scaled'])->decimal();

            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null */
    private function orderOutput(int $orderId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_production_outputs';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE production_order_id = %d",
            $orderId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        foreach ([
            'id', 'production_order_id', 'product_id', 'warehouse_id', 'batch_id',
            'quantity_scaled', 'unit_cost_irr', 'total_cost_irr',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['quantity'] = Quantity::fromScaled($row['quantity_scaled'])->decimal();

        return $row;
    }

    /** @param array<string, mixed> $data */
    private function movement(array $data): void
    {
        $this->insert('rishe_stock_movements', [
            'movement_id' => wp_generate_uuid4(),
            'type' => $data['type'],
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'batch_id' => $data['batch_id'],
            'reservation_id' => null,
            'transfer_group_id' => null,
            'quantity_scaled' => $data['quantity_scaled'],
            'unit_cost_irr' => $data['unit_cost_irr'],
            'reference_type' => 'production_order',
            'reference_id' => $data['reference_id'],
            'correlation_id' => $data['correlation_id'],
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s'], 'stock movement');
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatBom(array $row): array
    {
        foreach (['id', 'version', 'output_product_id', 'output_quantity_scaled', 'created_by'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['activated_by'] = $row['activated_by'] === null ? null : (int) $row['activated_by'];
        $row['output_quantity'] = Quantity::fromScaled($row['output_quantity_scaled'])->decimal();

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatOrder(array $row): array
    {
        foreach ([
            'id', 'bom_id', 'input_warehouse_id', 'output_warehouse_id', 'output_quantity_scaled',
            'labor_cost_irr', 'overhead_cost_irr', 'created_by',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['material_cost_irr', 'waste_cost_irr', 'total_cost_irr', 'unit_cost_irr', 'completed_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['output_quantity'] = Quantity::fromScaled($row['output_quantity_scaled'])->decimal();

        return $row;
    }

    private function sumCosts(int ...$costs): int
    {
        $total = 0;
        foreach ($costs as $cost) {
            $total = $this->checkedAdd($total, $cost);
        }

        return $total;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new ManufacturingDomainException('Production cost exceeds the supported integer range.');
        }

        return $left + $right;
    }
}
