<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use Rishe\Procurement\Domain\PurchaseOrderStatus;
use RuntimeException;

trait ProcurementOrderOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createPurchaseOrder(array $data, int $actorUserId): array
    {
        $supplierId = $this->positiveId($data['supplier_id'] ?? null, 'supplier_id');
        $warehouseId = $this->positiveId($data['warehouse_id'] ?? null, 'warehouse_id');
        $fiscalYear = $this->fiscalYear($data['fiscal_year'] ?? null);
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new ProcurementDomainException('A purchase order requires at least one line.');
        }

        $actor = $this->actor($actorUserId);
        $expectedLandedCost = $this->nonNegativeMoney(
            $data['estimated_landed_cost_irr'] ?? 0,
            'estimated_landed_cost_irr'
        );
        $externalReference = $this->nullableText($data['external_reference'] ?? null, 191);
        $idempotencyKey = $this->nullableText($data['idempotency_key'] ?? null, 100);
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);
        $expectedAt = $this->nullableDate($data['expected_at'] ?? null);

        return $this->transactions->run(function () use (
            $supplierId,
            $warehouseId,
            $fiscalYear,
            $rawLines,
            $actor,
            $expectedLandedCost,
            $externalReference,
            $idempotencyKey,
            $correlationId,
            $expectedAt
        ): array {
            $supplier = $this->requireSupplier($supplierId);
            $lines = [];
            $seenProducts = [];
            $merchandise = 0;
            $discount = 0;
            $tax = 0;

            foreach (array_values($rawLines) as $rawLine) {
                if (!is_array($rawLine)) {
                    throw new ProcurementDomainException('Purchase-order line must be an object.');
                }
                $productId = $this->positiveId($rawLine['product_id'] ?? null, 'product_id');
                if (isset($seenProducts[$productId])) {
                    throw new ProcurementDomainException('A product cannot appear twice in one purchase order.');
                }
                $seenProducts[$productId] = true;
                $product = $this->requireProduct($productId);
                $quantity = $this->quantity($rawLine['quantity'] ?? null);
                $unitPrice = $this->positiveMoney($rawLine['unit_price_irr'] ?? null, 'unit_price_irr');
                $lineDiscount = $this->nonNegativeMoney($rawLine['discount_irr'] ?? 0, 'discount_irr');
                $lineTax = $this->nonNegativeMoney($rawLine['tax_irr'] ?? 0, 'tax_irr');
                $gross = intdiv($quantity->scaled() * $unitPrice, Quantity::SCALE);
                if ($lineDiscount > $gross) {
                    throw new ProcurementDomainException('Purchase-line discount cannot exceed gross value.');
                }
                $inventoryValue = $gross - $lineDiscount;
                $lineTotal = $inventoryValue + $lineTax;
                if ($lineTotal < 1) {
                    throw new ProcurementDomainException('Purchase-order line total must be positive.');
                }

                $lines[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product['name'],
                    'sku' => (string) $product['sku'],
                    'quantity_scaled' => $quantity->scaled(),
                    'unit_price_irr' => $unitPrice,
                    'gross_irr' => $gross,
                    'discount_irr' => $lineDiscount,
                    'inventory_value_irr' => $inventoryValue,
                    'tax_irr' => $lineTax,
                    'line_total_irr' => $lineTotal,
                    'description' => $this->nullableText($rawLine['description'] ?? null, 500),
                ];
                $merchandise += $gross;
                $discount += $lineDiscount;
                $tax += $lineTax;
            }

            $netMerchandise = $merchandise - $discount;
            $estimatedTotal = $netMerchandise + $tax + $expectedLandedCost;
            $commercialPayload = [
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'fiscal_year' => $fiscalYear,
                'lines' => array_map(static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                    'unit_price_irr' => $line['unit_price_irr'],
                    'discount_irr' => $line['discount_irr'],
                    'tax_irr' => $line['tax_irr'],
                ], $lines),
                'estimated_landed_cost_irr' => $expectedLandedCost,
            ];
            $payloadHash = hash(
                'sha256',
                (string) json_encode($commercialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $result = $this->repository->createPurchaseOrder([
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'fiscal_year' => $fiscalYear,
                'status' => PurchaseOrderStatus::DRAFT->value,
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'expected_at' => $expectedAt,
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'merchandise_gross_irr' => $merchandise,
                'discount_irr' => $discount,
                'merchandise_net_irr' => $netMerchandise,
                'tax_irr' => $tax,
                'estimated_landed_cost_irr' => $expectedLandedCost,
                'estimated_total_irr' => $estimatedTotal,
                'payment_terms_days' => (int) $supplier['payment_terms_days'],
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
                'lines' => $lines,
            ]);
            if (!$result['idempotent']) {
                $this->audit->record(
                    'procurement.purchase_order.created',
                    'purchase_order',
                    (string) $result['id'],
                    [
                        'supplier_id' => $supplierId,
                        'warehouse_id' => $warehouseId,
                        'estimated_total_irr' => $estimatedTotal,
                    ],
                    $correlationId
                );
            }

            return $this->requirePurchaseOrder((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function approvePurchaseOrder(int $purchaseOrderId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($purchaseOrderId, $actor): array {
            $order = $this->repository->purchaseOrderForUpdate(
                $this->positiveId($purchaseOrderId, 'purchase_order_id')
            );
            if ($order === null) {
                throw new RuntimeException('Purchase order not found.');
            }
            $status = PurchaseOrderStatus::tryFrom((string) $order['status']);
            if ($status === null) {
                throw new ProcurementDomainException('Purchase-order status is invalid.');
            }
            $approvedStatuses = [
                PurchaseOrderStatus::APPROVED,
                PurchaseOrderStatus::PARTIALLY_RECEIVED,
                PurchaseOrderStatus::RECEIVED,
            ];
            if (in_array($status, $approvedStatuses, true)) {
                return $this->requirePurchaseOrder((int) $order['id']);
            }
            $status->assertCanApprove();
            $this->requireSupplier((int) $order['supplier_id']);
            foreach ($order['lines'] as $line) {
                $this->requireProduct((int) $line['product_id']);
            }

            $number = $this->repository->nextDocumentNumber('purchase_order', (int) $order['fiscal_year']);
            $this->repository->approvePurchaseOrder(
                (int) $order['id'],
                $number,
                $actor,
                gmdate('Y-m-d H:i:s')
            );
            $this->audit->record(
                'procurement.purchase_order.approved',
                'purchase_order',
                (string) $order['id'],
                ['document_number' => $number],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );

            return $this->requirePurchaseOrder((int) $order['id']);
        });
    }

    public function cancelPurchaseOrder(int $purchaseOrderId, int $actorUserId, string $reason = ''): void
    {
        $actor = $this->actor($actorUserId);
        $this->transactions->run(function () use ($purchaseOrderId, $actor, $reason): void {
            $order = $this->repository->purchaseOrderForUpdate(
                $this->positiveId($purchaseOrderId, 'purchase_order_id')
            );
            if ($order === null) {
                throw new RuntimeException('Purchase order not found.');
            }
            $status = PurchaseOrderStatus::tryFrom((string) $order['status']);
            if ($status === null) {
                throw new ProcurementDomainException('Purchase-order status is invalid.');
            }
            $hasReceipts = (int) ($order['received_liability_irr'] ?? 0) > 0;
            $status->assertCanCancel($hasReceipts);
            $this->repository->cancelPurchaseOrder((int) $order['id'], $actor, trim($reason));
            $this->audit->record(
                'procurement.purchase_order.cancelled',
                'purchase_order',
                (string) $order['id'],
                ['reason' => trim($reason)],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );
        });
    }

    /** @return array<string, mixed> */
    public function purchaseOrder(int $purchaseOrderId): array
    {
        return $this->requirePurchaseOrder($this->positiveId($purchaseOrderId, 'purchase_order_id'));
    }

    /** @return list<array<string, mixed>> */
    public function purchaseOrders(array $filters = []): array
    {
        return $this->repository->purchaseOrders([
            'supplier_id' => $this->optionalPositiveId($filters['supplier_id'] ?? null),
            'warehouse_id' => $this->optionalPositiveId($filters['warehouse_id'] ?? null),
            'status' => $this->nullableText($filters['status'] ?? null, 30),
        ]);
    }

    /** @return array<string, mixed> */
    private function requirePurchaseOrder(int $purchaseOrderId): array
    {
        $order = $this->repository->purchaseOrder($purchaseOrderId);
        if ($order === null) {
            throw new RuntimeException('Purchase order not found.');
        }

        return $order;
    }
}
