<?php

declare(strict_types=1);

namespace Rishe\Inventory\Application;

use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class InventoryCompletionService
{
    public function __construct(
        private readonly InventoryCompletionRepository $repository,
        private readonly InventoryService $inventory,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit
    ) {
    }

    /** @return array{selected: int, released: int, skipped: int, reservation_ids: list<int>} */
    public function releaseExpiredReservations(
        int $limit,
        int $actorUserId,
        ?int $productId = null,
        ?int $warehouseId = null
    ): array {
        $limit = max(1, min(500, $limit));
        $actorUserId = $this->actor($actorUserId);
        $ids = $this->repository->expiredReservationIds(
            $limit,
            gmdate('Y-m-d H:i:s'),
            $this->optionalPositiveId($productId),
            $this->optionalPositiveId($warehouseId)
        );
        $released = 0;
        $skipped = 0;
        foreach ($ids as $reservationId) {
            try {
                $this->inventory->releaseReservation($reservationId, $actorUserId);
                ++$released;
            } catch (InventoryDomainException) {
                ++$skipped;
            }
        }

        if ($ids !== []) {
            $this->audit->record('inventory.reservations.expired', 'stock_reservation_batch', gmdate('c'), [
                'selected' => count($ids),
                'released' => $released,
                'skipped' => $skipped,
                'reservation_ids' => $ids,
            ]);
        }

        return [
            'selected' => count($ids),
            'released' => $released,
            'skipped' => $skipped,
            'reservation_ids' => $ids,
        ];
    }

    /** @return array<string, mixed> */
    public function setAllocationMethod(int $productId, string $method, int $actorUserId): array
    {
        $productId = $this->positiveId($productId);
        $method = strtolower(trim($method));
        if (!in_array($method, ['fefo', 'fifo', 'lifo'], true)) {
            throw new InventoryDomainException('Allocation method must be fefo, fifo, or lifo.');
        }
        $actorUserId = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($productId, $method, $actorUserId): array {
            $result = $this->repository->updateAllocationMethod($productId, $method, $actorUserId);
            $this->audit->record('inventory.product.allocation_method_changed', 'product', (string) $productId, [
                'previous_method' => $result['previous_method'],
                'allocation_method' => $method,
                'actor_user_id' => $actorUserId,
            ]);

            return $result;
        });
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InventoryDomainException('A positive product id is required.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(?int $value): ?int
    {
        return $value === null ? null : $this->positiveId($value);
    }

    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new InventoryDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }
}
