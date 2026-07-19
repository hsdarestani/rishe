<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait B2BDispatchOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createDispatch(array $data, int $actorUserId): array
    {
        $accountId = $this->positiveId($data['account_id'] ?? null, 'account_id');
        $sourceWarehouseId = $this->positiveId($data['source_warehouse_id'] ?? null, 'source_warehouse_id');
        $fiscalYear = $this->fiscalYear($data['fiscal_year'] ?? null);
        $dispatchedAt = $this->dateTime($data['dispatched_at'] ?? null, 'dispatched_at');
        $idempotencyKey = $this->requiredReference($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new B2BDomainException('A consignment dispatch requires at least one line.');
        }
        $actor = $this->actor($actorUserId);
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);

        return $this->transactions->run(function () use (
            $accountId,
            $sourceWarehouseId,
            $fiscalYear,
            $dispatchedAt,
            $idempotencyKey,
            $rawLines,
            $actor,
            $correlationId,
            $data
        ): array {
            $account = $this->requireAccount($accountId, true);
            if (!in_array((string) $account['account_type'], ['consignment', 'hybrid'], true)) {
                throw new B2BDomainException('Account does not support consignment dispatches.');
            }
            $destinationWarehouseId = (int) $account['consignment_warehouse_id'];
            if ($sourceWarehouseId === $destinationWarehouseId) {
                throw new B2BDomainException('Source and consignment warehouses must be different.');
            }
            $source = $this->repository->warehouse($sourceWarehouseId);
            if ($source === null || !(bool) ($source['is_active'] ?? false)) {
                throw new B2BDomainException('Source warehouse is missing or inactive.');
            }

            $lines = [];
            $seen = [];
            foreach (array_values($rawLines) as $rawLine) {
                if (!is_array($rawLine)) {
                    throw new B2BDomainException('Dispatch line must be an object.');
                }
                $productId = $this->positiveId($rawLine['product_id'] ?? null, 'product_id');
                if (isset($seen[$productId])) {
                    throw new B2BDomainException('A product cannot appear twice in one dispatch.');
                }
                $seen[$productId] = true;
                $product = $this->repository->product($productId);
                if ($product === null || !(bool) ($product['is_active'] ?? false)) {
                    throw new B2BDomainException('Dispatch product is missing or inactive.');
                }
                $quantity = $this->quantity($rawLine['quantity'] ?? null);
                $lines[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product['name'],
                    'sku' => (string) $product['sku'],
                    'quantity_scaled' => $quantity->scaled(),
                ];
            }

            $commercial = [
                'account_id' => $accountId,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'lines' => array_map(static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                ], $lines),
            ];
            $payloadHash = hash(
                'sha256',
                (string) json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $number = $this->repository->nextDocumentNumber('consignment_dispatch', $fiscalYear);
            $result = $this->repository->createDispatch([
                'fiscal_year' => $fiscalYear,
                'document_number' => $number,
                'account_id' => $accountId,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'dispatched_at' => $dispatchedAt,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'reference' => $this->nullableText($data['reference'] ?? null, 191),
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
                'lines' => $lines,
            ]);
            if ($result['idempotent']) {
                return $this->requireDispatch((int) $result['id']);
            }

            foreach ($lines as $index => $line) {
                $transfer = $this->inventory->transfer([
                    'product_id' => $line['product_id'],
                    'from_warehouse_id' => $sourceWarehouseId,
                    'to_warehouse_id' => $destinationWarehouseId,
                    'quantity' => $this->scaledToDecimal($line['quantity_scaled']),
                    'reference_type' => 'consignment_dispatch',
                    'reference_id' => (string) $result['id'],
                    'correlation_id' => $correlationId,
                ], $actor);
                $this->repository->attachDispatchTransfer(
                    (int) $result['line_ids'][$index],
                    (string) $transfer['transfer_group_id']
                );
            }
            $this->repository->finalizeDispatch((int) $result['id']);
            $this->audit->record(
                'b2b.consignment.dispatched',
                'consignment_dispatch',
                (string) $result['id'],
                ['account_id' => $accountId, 'document_number' => $number],
                $correlationId
            );

            return $this->requireDispatch((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function dispatch(int $dispatchId): array
    {
        return $this->requireDispatch($this->positiveId($dispatchId, 'dispatch_id'));
    }

    /** @return list<array<string, mixed>> */
    public function dispatches(array $filters = []): array
    {
        return $this->repository->dispatches([
            'account_id' => $this->optionalPositiveId($filters['account_id'] ?? null),
            'status' => $this->nullableText($filters['status'] ?? null, 20),
        ]);
    }

    /** @return array<string, mixed> */
    private function requireDispatch(int $dispatchId): array
    {
        $dispatch = $this->repository->dispatch($dispatchId);
        if ($dispatch === null) {
            throw new RuntimeException('Consignment dispatch not found.');
        }

        return $dispatch;
    }
}
