<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use Rishe\Procurement\Domain\PurchaseOrderStatus;
use RuntimeException;

trait ProcurementReceiptOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function receivePurchaseOrder(int $purchaseOrderId, array $data, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $receivedAt = $this->dateTime($data['received_at'] ?? null, 'received_at');
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new ProcurementDomainException('A purchase receipt requires at least one line.');
        }
        $rawCosts = $data['landed_costs'] ?? [];
        if (!is_array($rawCosts)) {
            throw new ProcurementDomainException('Landed costs must be an array.');
        }
        $idempotencyKey = $this->requiredReference(
            $data['idempotency_key'] ?? null,
            'idempotency_key',
            100
        );
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);

        return $this->transactions->run(function () use (
            $purchaseOrderId,
            $data,
            $actor,
            $receivedAt,
            $rawLines,
            $rawCosts,
            $idempotencyKey,
            $correlationId
        ): array {
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
            $status->assertCanReceive();

            $orderLines = [];
            foreach ($order['lines'] as $line) {
                $orderLines[(int) $line['id']] = $line;
            }

            $receiptLines = [];
            $seen = [];
            foreach (array_values($rawLines) as $rawLine) {
                if (!is_array($rawLine)) {
                    throw new ProcurementDomainException('Receipt line must be an object.');
                }
                $orderLineId = $this->positiveId(
                    $rawLine['purchase_order_line_id'] ?? null,
                    'purchase_order_line_id'
                );
                if (isset($seen[$orderLineId])) {
                    throw new ProcurementDomainException('A purchase-order line cannot appear twice in one receipt.');
                }
                $seen[$orderLineId] = true;
                $line = $orderLines[$orderLineId] ?? null;
                if ($line === null) {
                    throw new ProcurementDomainException('Receipt line does not belong to the purchase order.');
                }
                $quantity = $this->quantity($rawLine['quantity'] ?? null);
                $values = $this->prorator->prorate(
                    (int) $line['quantity_scaled'],
                    (int) $line['received_quantity_scaled'],
                    $quantity->scaled(),
                    (int) $line['inventory_value_irr'],
                    (int) $line['tax_irr'],
                    (int) $line['received_inventory_value_irr'],
                    (int) $line['received_tax_irr']
                );
                $receiptLines[] = [
                    'purchase_order_line_id' => $orderLineId,
                    'product_id' => (int) $line['product_id'],
                    'product_name' => (string) $line['product_name'],
                    'quantity_scaled' => $quantity->scaled(),
                    'merchandise_value_irr' => $values['inventory_value_irr'],
                    'tax_irr' => $values['tax_irr'],
                    'landed_cost_irr' => 0,
                    'liability_irr' => $values['liability_irr'],
                    'batch_code' => $this->requiredCode($rawLine['batch_code'] ?? null),
                    'expiry_date' => $this->nullableDate($rawLine['expiry_date'] ?? null),
                ];
            }

            $costs = [];
            $landedTotal = 0;
            foreach (array_values($rawCosts) as $rawCost) {
                if (!is_array($rawCost)) {
                    throw new ProcurementDomainException('Landed-cost row must be an object.');
                }
                $amount = $this->positiveMoney($rawCost['amount_irr'] ?? null, 'landed_cost.amount_irr');
                $basis = strtolower(trim((string) ($rawCost['allocation_basis'] ?? 'value')));
                $shares = $this->landedCosts->allocate($amount, $receiptLines, $basis);
                foreach ($shares as $index => $share) {
                    $receiptLines[$index]['landed_cost_irr'] += $share;
                    $receiptLines[$index]['liability_irr'] += $share;
                }
                $costs[] = [
                    'cost_type' => $this->requiredReference($rawCost['cost_type'] ?? null, 'cost_type', 50),
                    'description' => $this->nullableText($rawCost['description'] ?? null, 500),
                    'amount_irr' => $amount,
                    'allocation_basis' => $basis,
                ];
                $landedTotal += $amount;
            }

            $merchandiseTotal = 0;
            $taxTotal = 0;
            $liabilityTotal = 0;
            foreach ($receiptLines as &$line) {
                $capitalized = $line['merchandise_value_irr'] + $line['landed_cost_irr'];
                $line['unit_cost_irr'] = intdiv(
                    ($capitalized * Quantity::SCALE) + intdiv($line['quantity_scaled'], 2),
                    $line['quantity_scaled']
                );
                $merchandiseTotal += $line['merchandise_value_irr'];
                $taxTotal += $line['tax_irr'];
                $liabilityTotal += $line['liability_irr'];
            }
            unset($line);

            $commercialPayload = [
                'purchase_order_id' => (int) $order['id'],
                'received_at' => $receivedAt,
                'lines' => array_map(static fn (array $line): array => [
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                    'batch_code' => $line['batch_code'],
                    'expiry_date' => $line['expiry_date'],
                ], $receiptLines),
                'costs' => $costs,
            ];
            $payloadHash = hash(
                'sha256',
                (string) json_encode($commercialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $result = $this->repository->createReceipt([
                'fiscal_year' => (int) $order['fiscal_year'],
                'purchase_order_id' => (int) $order['id'],
                'supplier_id' => (int) $order['supplier_id'],
                'warehouse_id' => (int) $order['warehouse_id'],
                'received_at' => $receivedAt,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'merchandise_value_irr' => $merchandiseTotal,
                'tax_irr' => $taxTotal,
                'landed_cost_irr' => $landedTotal,
                'liability_irr' => $liabilityTotal,
                'due_date' => gmdate(
                    'Y-m-d',
                    strtotime($receivedAt . ' +' . (int) $order['payment_terms_days'] . ' days')
                ),
                'reference' => $this->nullableText($data['reference'] ?? null, 191),
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
                'lines' => $receiptLines,
                'landed_costs' => $costs,
            ]);
            if ($result['idempotent']) {
                return $this->requireReceipt((int) $result['id']);
            }

            foreach ($receiptLines as $index => $line) {
                $batchId = $this->inventory->receive([
                    'product_id' => $line['product_id'],
                    'warehouse_id' => (int) $order['warehouse_id'],
                    'batch_code' => $line['batch_code'],
                    'quantity' => $this->scaledToDecimal($line['quantity_scaled']),
                    'unit_cost_irr' => $line['unit_cost_irr'],
                    'received_at' => $receivedAt,
                    'expiry_date' => $line['expiry_date'],
                    'reference_type' => 'purchase_receipt',
                    'reference_id' => (string) $result['id'],
                    'correlation_id' => $correlationId,
                ], $actor);
                $this->repository->attachInventoryBatch((int) $result['line_ids'][$index], $batchId);
                $receiptLines[$index]['inventory_batch_id'] = $batchId;
            }

            $complete = true;
            foreach ($order['lines'] as $line) {
                $added = 0;
                foreach ($receiptLines as $receiptLine) {
                    if ((int) $receiptLine['purchase_order_line_id'] === (int) $line['id']) {
                        $added = (int) $receiptLine['quantity_scaled'];
                        break;
                    }
                }
                if ((int) $line['received_quantity_scaled'] + $added < (int) $line['quantity_scaled']) {
                    $complete = false;
                    break;
                }
            }
            $newStatus = $complete
                ? PurchaseOrderStatus::RECEIVED->value
                : PurchaseOrderStatus::PARTIALLY_RECEIVED->value;
            $receipt = [
                'id' => (int) $result['id'],
                'document_number' => (int) $result['document_number'],
                'purchase_order_id' => (int) $order['id'],
                'supplier_id' => (int) $order['supplier_id'],
                'supplier_floating_detail_id' => $order['supplier_floating_detail_id'] ?? null,
                'supplier_payable_subsidiary_ledger_id' =>
                    $order['supplier_payable_subsidiary_ledger_id'] ?? null,
                'warehouse_id' => (int) $order['warehouse_id'],
                'merchandise_value_irr' => $merchandiseTotal,
                'tax_irr' => $taxTotal,
                'landed_cost_irr' => $landedTotal,
                'liability_irr' => $liabilityTotal,
                'correlation_id' => $correlationId,
                'lines' => $receiptLines,
            ];
            $accounting = $this->accounting->postReceipt($receipt, $actor);
            $this->repository->finalizeReceipt(
                (int) $result['id'],
                (int) $order['id'],
                $newStatus,
                $accounting
            );
            $this->audit->record(
                'procurement.receipt.posted',
                'purchase_receipt',
                (string) $result['id'],
                [
                    'purchase_order_id' => (int) $order['id'],
                    'liability_irr' => $liabilityTotal,
                    'landed_cost_irr' => $landedTotal,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ],
                $correlationId
            );

            return $this->requireReceipt((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function receipt(int $receiptId): array
    {
        return $this->requireReceipt($this->positiveId($receiptId, 'receipt_id'));
    }

    /** @return list<array<string, mixed>> */
    public function receipts(array $filters = []): array
    {
        return $this->repository->receipts([
            'purchase_order_id' => $this->optionalPositiveId($filters['purchase_order_id'] ?? null),
            'supplier_id' => $this->optionalPositiveId($filters['supplier_id'] ?? null),
            'warehouse_id' => $this->optionalPositiveId($filters['warehouse_id'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    private function requireReceipt(int $receiptId): array
    {
        $receipt = $this->repository->receipt($receiptId);
        if ($receipt === null) {
            throw new RuntimeException('Purchase receipt not found.');
        }

        return $receipt;
    }

    private function scaledToDecimal(int $scaled): string
    {
        $whole = intdiv($scaled, Quantity::SCALE);
        $fraction = str_pad((string) ($scaled % Quantity::SCALE), 4, '0', STR_PAD_LEFT);

        return $whole . '.' . $fraction;
    }
}
