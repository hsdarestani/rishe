<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure;

use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Domain\Quantity;
use RuntimeException;

final class WpdbStockMutationGateway
{
    public function __construct(private readonly FifoAllocator $allocator)
    {
    }

    /** @param array<string, mixed> $data */
    public function receive(array $data): int
    {
        $this->assertWarehouseActive((int) $data['warehouse_id']);
        $this->assertProductActive((int) $data['product_id']);
        $now = current_time('mysql', true);
        $batchId = $this->insert('rishe_inventory_batches', [
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'batch_code' => $data['batch_code'],
            'origin_batch_id' => null,
            'received_at' => $data['received_at'],
            'expiry_date' => $data['expiry_date'],
            'unit_cost_irr' => $data['unit_cost_irr'],
            'quantity_on_hand' => $data['quantity_scaled'],
            'quantity_reserved' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s'], 'inventory batch');

        $this->movement($data + [
            'type' => 'receipt',
            'batch_id' => $batchId,
            'reservation_id' => null,
            'transfer_group_id' => null,
        ]);

        return $batchId;
    }

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool} */
    public function reserve(array $data): array
    {
        global $wpdb;

        $this->assertWarehouseActive((int) $data['warehouse_id']);
        $this->assertProductActive((int) $data['product_id']);
        $table = $wpdb->prefix . 'rishe_stock_reservations';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE reference_type = %s AND reference_id = %s
             AND product_id = %d AND warehouse_id = %d LIMIT 1 FOR UPDATE",
            $data['reference_type'],
            $data['reference_id'],
            $data['product_id'],
            $data['warehouse_id']
        ), ARRAY_A);
        if (is_array($existing)) {
            if ((string) $existing['status'] === 'active'
                && (int) $existing['quantity_scaled'] === (int) $data['quantity_scaled']) {
                return ['id' => (int) $existing['id'], 'idempotent' => true];
            }

            throw new InventoryDomainException('Reservation reference already exists and cannot be reused.');
        }

        $allocations = $this->allocator->allocate(
            $this->lockBatches((int) $data['product_id'], (int) $data['warehouse_id'], (string) $data['inventory_method']),
            (int) $data['quantity_scaled']
        );
        $now = current_time('mysql', true);
        $reservationId = $this->insert('rishe_stock_reservations', [
            'reservation_key' => wp_generate_uuid4(),
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'quantity_scaled' => $data['quantity_scaled'],
            'status' => 'active',
            'expires_at' => $data['expires_at'],
            'committed_cogs_irr' => null,
            'committed_at' => null,
            'released_at' => null,
            'created_by' => $data['actor_user_id'],
            'correlation_id' => $data['correlation_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s'], 'stock reservation');

        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        foreach ($allocations as $allocation) {
            $quantity = (int) $allocation['quantity_scaled'];
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$batches} SET quantity_reserved = quantity_reserved + %d, updated_at = %s
                 WHERE id = %d AND status = 'active' AND (quantity_on_hand - quantity_reserved) >= %d",
                $quantity,
                $now,
                $allocation['batch_id'],
                $quantity
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to reserve the selected inventory batch.');
            }

            $this->insert('rishe_stock_reservation_allocations', [
                'reservation_id' => $reservationId,
                'batch_id' => $allocation['batch_id'],
                'quantity_scaled' => $quantity,
                'unit_cost_irr' => $allocation['unit_cost_irr'],
                'created_at' => $now,
            ], ['%d', '%d', '%d', '%d', '%s'], 'reservation allocation');
        }

        return ['id' => $reservationId, 'idempotent' => false];
    }

    /** @return array<string, mixed> */
    public function releaseReservation(int $reservationId, int $actorUserId): array
    {
        global $wpdb;
        unset($actorUserId);

        $reservation = $this->reservation($reservationId);
        if ((string) $reservation['status'] === 'released') {
            $reservation['idempotent'] = true;

            return $reservation;
        }
        if ((string) $reservation['status'] !== 'active') {
            throw new InventoryDomainException('Only an active reservation can be released.');
        }

        $now = current_time('mysql', true);
        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        foreach ($this->allocations($reservationId) as $allocation) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$batches} SET quantity_reserved = quantity_reserved - %d, updated_at = %s
                 WHERE id = %d AND quantity_reserved >= %d",
                $allocation['quantity_scaled'],
                $now,
                $allocation['batch_id'],
                $allocation['quantity_scaled']
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to release reserved batch quantity.');
            }
        }

        $this->markReservation($reservationId, 'released', null, null, $now);
        $reservation['idempotent'] = false;

        return $reservation;
    }

    /** @return array<string, mixed> */
    public function commitReservation(int $reservationId, int $actorUserId): array
    {
        global $wpdb;

        $reservation = $this->reservation($reservationId);
        if ((string) $reservation['status'] === 'committed') {
            return [
                'quantity_scaled' => (int) $reservation['quantity_scaled'],
                'cogs_irr' => (int) $reservation['committed_cogs_irr'],
                'correlation_id' => $reservation['correlation_id'],
                'idempotent' => true,
            ];
        }
        if ((string) $reservation['status'] !== 'active') {
            throw new InventoryDomainException('Only an active reservation can be committed.');
        }
        if ($reservation['expires_at'] !== null
            && (string) $reservation['expires_at'] < current_time('mysql', true)) {
            throw new InventoryDomainException('Expired reservation must be released before stock can be used.');
        }

        $now = current_time('mysql', true);
        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $cogs = 0;
        foreach ($this->allocations($reservationId) as $allocation) {
            $quantity = (int) $allocation['quantity_scaled'];
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$batches}
                 SET quantity_on_hand = quantity_on_hand - %d,
                     quantity_reserved = quantity_reserved - %d,
                     status = IF(quantity_on_hand - %d = 0, 'depleted', status), updated_at = %s
                 WHERE id = %d AND quantity_on_hand >= %d AND quantity_reserved >= %d",
                $quantity,
                $quantity,
                $quantity,
                $now,
                $allocation['batch_id'],
                $quantity,
                $quantity
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to commit reserved inventory batch.');
            }

            $unitCost = (int) $allocation['unit_cost_irr'];
            $cogs += intdiv($quantity * $unitCost, Quantity::SCALE);
            $this->movement([
                'type' => 'issue',
                'product_id' => $reservation['product_id'],
                'warehouse_id' => $reservation['warehouse_id'],
                'batch_id' => $allocation['batch_id'],
                'reservation_id' => $reservationId,
                'transfer_group_id' => null,
                'quantity_scaled' => -$quantity,
                'unit_cost_irr' => $unitCost,
                'reference_type' => $reservation['reference_type'],
                'reference_id' => $reservation['reference_id'],
                'correlation_id' => $reservation['correlation_id'],
                'actor_user_id' => $actorUserId,
            ]);
        }

        $this->markReservation($reservationId, 'committed', $cogs, $now, null);

        return [
            'quantity_scaled' => (int) $reservation['quantity_scaled'],
            'cogs_irr' => $cogs,
            'correlation_id' => $reservation['correlation_id'],
            'idempotent' => false,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function transfer(array $data): array
    {
        global $wpdb;

        $this->assertWarehouseActive((int) $data['from_warehouse_id']);
        $this->assertWarehouseActive((int) $data['to_warehouse_id']);
        $this->assertProductActive((int) $data['product_id']);
        $allocations = $this->allocator->allocate(
            $this->lockBatches((int) $data['product_id'], (int) $data['from_warehouse_id'], (string) $data['inventory_method']),
            (int) $data['quantity_scaled']
        );
        $groupId = wp_generate_uuid4();
        $now = current_time('mysql', true);
        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $value = 0;

        foreach ($allocations as $allocation) {
            $quantity = (int) $allocation['quantity_scaled'];
            $source = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$batches} WHERE id = %d FOR UPDATE",
                $allocation['batch_id']
            ), ARRAY_A);
            if (!is_array($source)) {
                throw new RuntimeException('Transfer source batch could not be loaded.');
            }

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$batches} SET quantity_on_hand = quantity_on_hand - %d,
                 status = IF(quantity_on_hand - %d = 0, 'depleted', status), updated_at = %s
                 WHERE id = %d AND (quantity_on_hand - quantity_reserved) >= %d",
                $quantity,
                $quantity,
                $now,
                $allocation['batch_id'],
                $quantity
            ));
            if ($updated !== 1) {
                throw new RuntimeException('Unable to deduct transfer source batch.');
            }

            $destinationId = $this->insert('rishe_inventory_batches', [
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['to_warehouse_id'],
                'batch_code' => $source['batch_code'],
                'origin_batch_id' => $allocation['batch_id'],
                'received_at' => $now,
                'expiry_date' => $source['expiry_date'],
                'unit_cost_irr' => $allocation['unit_cost_irr'],
                'quantity_on_hand' => $quantity,
                'quantity_reserved' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s'], 'destination batch');

            $common = [
                'product_id' => $data['product_id'],
                'reservation_id' => null,
                'transfer_group_id' => $groupId,
                'unit_cost_irr' => $allocation['unit_cost_irr'],
                'reference_type' => $data['reference_type'],
                'reference_id' => $data['reference_id'],
                'correlation_id' => $data['correlation_id'],
                'actor_user_id' => $data['actor_user_id'],
            ];
            $this->movement($common + [
                'type' => 'transfer_out',
                'warehouse_id' => $data['from_warehouse_id'],
                'batch_id' => $allocation['batch_id'],
                'quantity_scaled' => -$quantity,
            ]);
            $this->movement($common + [
                'type' => 'transfer_in',
                'warehouse_id' => $data['to_warehouse_id'],
                'batch_id' => $destinationId,
                'quantity_scaled' => $quantity,
            ]);
            $value += intdiv($quantity * (int) $allocation['unit_cost_irr'], Quantity::SCALE);
        }

        return [
            'quantity_scaled' => (int) $data['quantity_scaled'],
            'inventory_value_irr' => $value,
            'transfer_group_id' => $groupId,
        ];
    }

    /** @return list<array{id: int, available_scaled: int, unit_cost_irr: int, batch_code: string}> */
    private function lockBatches(int $productId, int $warehouseId, string $method): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_inventory_batches';
        $direction = $method === 'lifo' ? 'DESC' : 'ASC';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, batch_code, unit_cost_irr,
                    (quantity_on_hand - quantity_reserved) AS available_scaled
             FROM {$table} WHERE product_id = %d AND warehouse_id = %d AND status = 'active'
             AND quantity_on_hand > quantity_reserved AND (expiry_date IS NULL OR expiry_date >= %s)
             ORDER BY received_at {$direction}, id {$direction} FOR UPDATE",
            $productId,
            $warehouseId,
            gmdate('Y-m-d')
        ), ARRAY_A);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'available_scaled' => (int) $row['available_scaled'],
            'unit_cost_irr' => (int) $row['unit_cost_irr'],
            'batch_code' => (string) $row['batch_code'],
        ], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed> */
    private function reservation(int $reservationId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_stock_reservations';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $reservationId
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new InventoryDomainException('Stock reservation was not found.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function allocations(int $reservationId): array
    {
        global $wpdb;

        $allocations = $wpdb->prefix . 'rishe_stock_reservation_allocations';
        $batches = $wpdb->prefix . 'rishe_inventory_batches';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$allocations} a INNER JOIN {$batches} b ON b.id = a.batch_id
             WHERE a.reservation_id = %d ORDER BY a.id FOR UPDATE",
            $reservationId
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function markReservation(
        int $reservationId,
        string $status,
        ?int $cogs,
        ?string $committedAt,
        ?string $releasedAt
    ): void {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_stock_reservations',
            [
                'status' => $status,
                'committed_cogs_irr' => $cogs,
                'committed_at' => $committedAt,
                'released_at' => $releasedAt,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $reservationId],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to update stock reservation state.');
        }
    }

    /** @param array<string, mixed> $data */
    private function movement(array $data): int
    {
        return $this->insert('rishe_stock_movements', [
            'movement_id' => wp_generate_uuid4(),
            'type' => $data['type'],
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'batch_id' => $data['batch_id'],
            'reservation_id' => $data['reservation_id'],
            'transfer_group_id' => $data['transfer_group_id'],
            'quantity_scaled' => $data['quantity_scaled'],
            'unit_cost_irr' => $data['unit_cost_irr'],
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'correlation_id' => $data['correlation_id'],
            'actor_user_id' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s'], 'stock movement');
    }

    private function assertProductActive(int $productId): void
    {
        $this->assertActive('rishe_products', $productId, 'Product');
    }

    private function assertWarehouseActive(int $warehouseId): void
    {
        $this->assertActive('rishe_warehouses', $warehouseId, 'Warehouse');
    }

    private function assertActive(string $suffix, int $id, string $entity): void
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $active = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$table} WHERE id = %d", $id));
        if ((int) $active !== 1) {
            throw new InventoryDomainException($entity . ' is missing or inactive.');
        }
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
}
