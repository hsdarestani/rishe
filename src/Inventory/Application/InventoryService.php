<?php

declare(strict_types=1);

namespace Rishe\Inventory\Application;

use DateTimeImmutable;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class InventoryService
{
    public function __construct(
        private readonly InventoryRepository $repository,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createWarehouse(array $data): int
    {
        $type = strtolower(trim((string) ($data['type'] ?? 'other')));
        if (!in_array($type, ['central', 'branch', 'workbench', 'consignment', 'other'], true)) {
            throw new InventoryDomainException('Warehouse type is invalid.');
        }

        $payload = [
            'code' => $this->requiredCode($data['code'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'type' => $type,
        ];

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createWarehouse($payload);
            $this->audit->record('inventory.warehouse.created', 'warehouse', (string) $id, $payload);

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function createProduct(array $data): int
    {
        $method = strtolower(trim((string) ($data['inventory_method'] ?? 'fifo')));
        if (!in_array($method, ['fifo', 'lifo'], true)) {
            throw new InventoryDomainException('Inventory method must be fifo or lifo.');
        }

        $wcProductId = $data['wc_product_id'] ?? null;
        $payload = [
            'sku' => $this->requiredCode($data['sku'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'base_unit' => $this->requiredUnit($data['base_unit'] ?? null),
            'inventory_method' => $method,
            'wc_product_id' => $wcProductId === null ? null : $this->positiveId($wcProductId, 'wc_product_id'),
        ];

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createProduct($payload);
            $this->audit->record('inventory.product.created', 'product', (string) $id, $payload);

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function receiveStock(array $data, int $actorUserId): int
    {
        $productId = $this->positiveId($data['product_id'] ?? null, 'product_id');
        $warehouseId = $this->positiveId($data['warehouse_id'] ?? null, 'warehouse_id');
        $product = $this->activeProduct($productId);
        $quantity = Quantity::fromInput($data['quantity'] ?? null);
        $unitCost = $this->nonNegativeMoney($data['unit_cost_irr'] ?? null, 'unit_cost_irr');
        $receivedAt = $this->dateTime($data['received_at'] ?? null, 'received_at');
        $expiryDate = $this->nullableDate($data['expiry_date'] ?? null);
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);

        $payload = [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'batch_code' => $this->requiredCode($data['batch_code'] ?? null),
            'quantity_scaled' => $quantity->scaled(),
            'unit_cost_irr' => $unitCost,
            'received_at' => $receivedAt,
            'expiry_date' => $expiryDate,
            'reference_type' => $this->nullableText($data['reference_type'] ?? 'purchase', 50),
            'reference_id' => $this->nullableText($data['reference_id'] ?? null, 191),
            'correlation_id' => $correlationId,
            'actor_user_id' => $this->actor($actorUserId),
            'inventory_method' => (string) $product['inventory_method'],
        ];

        return $this->transactions->run(function () use ($payload): int {
            $batchId = $this->repository->receive($payload);
            $this->audit->record(
                'inventory.stock.received',
                'inventory_batch',
                (string) $batchId,
                ['quantity_scaled' => $payload['quantity_scaled'], 'unit_cost_irr' => $payload['unit_cost_irr']],
                $payload['correlation_id']
            );

            return $batchId;
        });
    }

    /** @param array<string, mixed> $data */
    public function reserveStock(array $data, int $actorUserId): int
    {
        $productId = $this->positiveId($data['product_id'] ?? null, 'product_id');
        $product = $this->activeProduct($productId);
        $quantity = Quantity::fromInput($data['quantity'] ?? null);
        $payload = [
            'product_id' => $productId,
            'warehouse_id' => $this->positiveId($data['warehouse_id'] ?? null, 'warehouse_id'),
            'quantity_scaled' => $quantity->scaled(),
            'reference_type' => $this->requiredReference($data['reference_type'] ?? null, 'reference_type'),
            'reference_id' => $this->requiredReference($data['reference_id'] ?? null, 'reference_id'),
            'expires_at' => $this->nullableDateTime($data['expires_at'] ?? null),
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'actor_user_id' => $this->actor($actorUserId),
            'inventory_method' => (string) $product['inventory_method'],
        ];

        return $this->transactions->run(function () use ($payload): int {
            $result = $this->repository->reserve($payload);
            $reservationId = (int) $result['id'];
            if (!(bool) ($result['idempotent'] ?? false)) {
                $this->audit->record(
                    'inventory.stock.reserved',
                    'stock_reservation',
                    (string) $reservationId,
                    ['quantity_scaled' => $payload['quantity_scaled']],
                    $payload['correlation_id']
                );
            }

            return $reservationId;
        });
    }

    public function releaseReservation(int $reservationId, int $actorUserId): void
    {
        $this->transactions->run(function () use ($reservationId, $actorUserId): void {
            $result = $this->repository->releaseReservation($reservationId, $this->actor($actorUserId));
            if ((bool) ($result['idempotent'] ?? false)) {
                return;
            }

            $this->audit->record(
                'inventory.reservation.released',
                'stock_reservation',
                (string) $reservationId,
                ['quantity_scaled' => (int) $result['quantity_scaled']],
                $this->nullableText($result['correlation_id'] ?? null, 64)
            );
        });
    }

    /** @return array{quantity_scaled: int, cogs_irr: int} */
    public function commitReservation(int $reservationId, int $actorUserId): array
    {
        return $this->transactions->run(function () use ($reservationId, $actorUserId): array {
            $result = $this->repository->commitReservation($reservationId, $this->actor($actorUserId));
            if (!(bool) ($result['idempotent'] ?? false)) {
                $this->audit->record(
                    'inventory.reservation.committed',
                    'stock_reservation',
                    (string) $reservationId,
                    [
                        'quantity_scaled' => (int) $result['quantity_scaled'],
                        'cogs_irr' => (int) $result['cogs_irr'],
                    ],
                    $this->nullableText($result['correlation_id'] ?? null, 64)
                );
            }

            return [
                'quantity_scaled' => (int) $result['quantity_scaled'],
                'cogs_irr' => (int) $result['cogs_irr'],
            ];
        });
    }

    /** @param array<string, mixed> $data @return array{quantity_scaled: int, inventory_value_irr: int, transfer_group_id: string} */
    public function transferStock(array $data, int $actorUserId): array
    {
        $productId = $this->positiveId($data['product_id'] ?? null, 'product_id');
        $product = $this->activeProduct($productId);
        $from = $this->positiveId($data['from_warehouse_id'] ?? null, 'from_warehouse_id');
        $to = $this->positiveId($data['to_warehouse_id'] ?? null, 'to_warehouse_id');
        if ($from === $to) {
            throw new InventoryDomainException('Source and destination warehouses must be different.');
        }

        $payload = [
            'product_id' => $productId,
            'from_warehouse_id' => $from,
            'to_warehouse_id' => $to,
            'quantity_scaled' => Quantity::fromInput($data['quantity'] ?? null)->scaled(),
            'reference_type' => $this->nullableText($data['reference_type'] ?? 'transfer', 50),
            'reference_id' => $this->nullableText($data['reference_id'] ?? null, 191),
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'actor_user_id' => $this->actor($actorUserId),
            'inventory_method' => (string) $product['inventory_method'],
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->transfer($payload);
            $this->audit->record(
                'inventory.stock.transferred',
                'stock_transfer',
                (string) $result['transfer_group_id'],
                [
                    'quantity_scaled' => (int) $result['quantity_scaled'],
                    'inventory_value_irr' => (int) $result['inventory_value_irr'],
                ],
                $payload['correlation_id']
            );

            return [
                'quantity_scaled' => (int) $result['quantity_scaled'],
                'inventory_value_irr' => (int) $result['inventory_value_irr'],
                'transfer_group_id' => (string) $result['transfer_group_id'],
            ];
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function stockSummary(array $filters): array
    {
        return $this->repository->stockSummary([
            'product_id' => $this->optionalPositiveId($filters['product_id'] ?? null),
            'warehouse_id' => $this->optionalPositiveId($filters['warehouse_id'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function ledger(array $filters): array
    {
        return $this->repository->ledger([
            'product_id' => $this->optionalPositiveId($filters['product_id'] ?? null),
            'warehouse_id' => $this->optionalPositiveId($filters['warehouse_id'] ?? null),
            'from' => $this->nullableDate($filters['from'] ?? null),
            'to' => $this->nullableDate($filters['to'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    private function activeProduct(int $productId): array
    {
        $product = $this->repository->product($productId);
        if ($product === null || !(bool) ($product['is_active'] ?? false)) {
            throw new InventoryDomainException('Product is missing or inactive.');
        }

        return $product;
    }

    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new InventoryDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InventoryDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'filter');
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new InventoryDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function requiredCode(mixed $value): string
    {
        $text = strtoupper(trim((string) $value));
        if ($text === '' || strlen($text) > 100 || !preg_match('/^[A-Z0-9._-]+$/', $text)) {
            throw new InventoryDomainException('Code must contain only letters, digits, dot, dash, or underscore.');
        }

        return $text;
    }

    private function requiredName(mixed $value): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 191) {
            throw new InventoryDomainException('Name is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function requiredUnit(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '' || strlen($text) > 30) {
            throw new InventoryDomainException('Base unit is required.');
        }

        return $text;
    }

    private function requiredReference(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 191) {
            throw new InventoryDomainException($field . ' is required.');
        }

        return $text;
    }

    private function dateTime(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return gmdate('Y-m-d H:i:s');
        }

        $text = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $text) {
            throw new InventoryDomainException($field . ' must use YYYY-MM-DD HH:MM:SS.');
        }

        return $text;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->dateTime($value, 'expires_at');
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new InventoryDomainException('Date must use YYYY-MM-DD.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $maxLength) {
            throw new InventoryDomainException('Text value exceeds its maximum length.');
        }

        return $text;
    }
}
