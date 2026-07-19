<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait B2BReturnOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function returnConsignment(int $dispatchId, array $data, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $returnedAt = $this->dateTime($data['returned_at'] ?? null, 'returned_at');
        $idempotencyKey = $this->requiredReference($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new B2BDomainException('A consignment return requires at least one line.');
        }
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);

        return $this->transactions->run(function () use (
            $dispatchId,
            $data,
            $actor,
            $returnedAt,
            $idempotencyKey,
            $rawLines,
            $correlationId
        ): array {
            $dispatch = $this->repository->dispatchForUpdate(
                $this->positiveId($dispatchId, 'dispatch_id')
            );
            if ($dispatch === null || !in_array((string) $dispatch['status'], ['active', 'partially_settled'], true)) {
                throw new B2BDomainException('Consignment dispatch is not open for returns.');
            }
            $this->requireAccount((int) $dispatch['account_id'], true);
            $destinationWarehouseId = isset($data['destination_warehouse_id'])
                ? $this->positiveId($data['destination_warehouse_id'], 'destination_warehouse_id')
                : (int) $dispatch['source_warehouse_id'];
            if ($destinationWarehouseId === (int) $dispatch['destination_warehouse_id']) {
                throw new B2BDomainException('Return destination must differ from the consignment warehouse.');
            }
            $destination = $this->repository->warehouse($destinationWarehouseId);
            if ($destination === null || !(bool) ($destination['is_active'] ?? false)) {
                throw new B2BDomainException('Return destination warehouse is missing or inactive.');
            }

            $dispatchLines = [];
            foreach ($dispatch['lines'] as $line) {
                $dispatchLines[(int) $line['id']] = $line;
            }
            $lines = [];
            $seen = [];
            foreach (array_values($rawLines) as $rawLine) {
                if (!is_array($rawLine)) {
                    throw new B2BDomainException('Return line must be an object.');
                }
                $dispatchLineId = $this->positiveId(
                    $rawLine['dispatch_line_id'] ?? null,
                    'dispatch_line_id'
                );
                if (isset($seen[$dispatchLineId])) {
                    throw new B2BDomainException('A dispatch line cannot appear twice in one return.');
                }
                $seen[$dispatchLineId] = true;
                $dispatchLine = $dispatchLines[$dispatchLineId] ?? null;
                if ($dispatchLine === null) {
                    throw new B2BDomainException('Return line does not belong to the dispatch.');
                }
                $quantity = $this->quantity($rawLine['quantity'] ?? null);
                $this->lineBalance->assertCanReturn(
                    (int) $dispatchLine['quantity_scaled'],
                    (int) $dispatchLine['sold_quantity_scaled'],
                    (int) $dispatchLine['returned_quantity_scaled'],
                    $quantity->scaled()
                );
                $lines[] = [
                    'dispatch_line_id' => $dispatchLineId,
                    'product_id' => (int) $dispatchLine['product_id'],
                    'product_name' => (string) $dispatchLine['product_name'],
                    'quantity_scaled' => $quantity->scaled(),
                ];
            }

            $commercial = [
                'dispatch_id' => (int) $dispatch['id'],
                'destination_warehouse_id' => $destinationWarehouseId,
                'lines' => array_map(static fn (array $line): array => [
                    'dispatch_line_id' => $line['dispatch_line_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                ], $lines),
            ];
            $payloadHash = hash(
                'sha256',
                (string) json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $number = $this->repository->nextDocumentNumber(
                'consignment_return',
                (int) $dispatch['fiscal_year']
            );
            $result = $this->repository->createReturn([
                'fiscal_year' => (int) $dispatch['fiscal_year'],
                'document_number' => $number,
                'dispatch_id' => (int) $dispatch['id'],
                'account_id' => (int) $dispatch['account_id'],
                'source_warehouse_id' => (int) $dispatch['destination_warehouse_id'],
                'destination_warehouse_id' => $destinationWarehouseId,
                'returned_at' => $returnedAt,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
                'lines' => $lines,
            ]);
            if ($result['idempotent']) {
                $document = $this->repository->returnDocument((int) $result['id']);
                if ($document === null) {
                    throw new RuntimeException('Consignment return not found.');
                }

                return $document;
            }

            $updates = [];
            foreach ($lines as $index => $line) {
                $transfer = $this->inventory->transfer([
                    'product_id' => $line['product_id'],
                    'from_warehouse_id' => (int) $dispatch['destination_warehouse_id'],
                    'to_warehouse_id' => $destinationWarehouseId,
                    'quantity' => $this->scaledToDecimal($line['quantity_scaled']),
                    'reference_type' => 'consignment_return',
                    'reference_id' => (string) $result['id'],
                    'correlation_id' => $correlationId,
                ], $actor);
                $this->repository->attachReturnTransfer(
                    (int) $result['line_ids'][$index],
                    (string) $transfer['transfer_group_id']
                );
                $updates[] = [
                    'dispatch_line_id' => $line['dispatch_line_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                ];
            }

            $closed = true;
            foreach ($dispatch['lines'] as $dispatchLine) {
                $added = 0;
                foreach ($updates as $update) {
                    if ((int) $update['dispatch_line_id'] === (int) $dispatchLine['id']) {
                        $added = (int) $update['quantity_scaled'];
                        break;
                    }
                }
                if (
                    (int) $dispatchLine['sold_quantity_scaled']
                    + (int) $dispatchLine['returned_quantity_scaled']
                    + $added
                    < (int) $dispatchLine['quantity_scaled']
                ) {
                    $closed = false;
                    break;
                }
            }
            $dispatchStatus = $closed ? 'closed' : 'partially_settled';
            $this->repository->finalizeReturn(
                (int) $result['id'],
                (int) $dispatch['id'],
                $updates,
                $dispatchStatus
            );
            $this->audit->record(
                'b2b.consignment.returned',
                'consignment_return',
                (string) $result['id'],
                ['dispatch_id' => (int) $dispatch['id'], 'document_number' => $number],
                $correlationId
            );

            $document = $this->repository->returnDocument((int) $result['id']);
            if ($document === null) {
                throw new RuntimeException('Consignment return not found after posting.');
            }

            return $document;
        });
    }
}
