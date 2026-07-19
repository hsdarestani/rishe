<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement\Fakes;

use Rishe\Procurement\Application\ProcurementRepository;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;

final class InMemoryProcurementRepository implements ProcurementRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $suppliers = [
        1 => [
            'id' => 1,
            'code' => 'SUP-1',
            'name' => 'Supplier One',
            'payment_terms_days' => 10,
            'credit_limit_irr' => 10000000,
            'payable_subsidiary_ledger_id' => 30,
            'floating_detail_id' => 40,
            'is_active' => true,
        ],
    ];
    /** @var array<int, array<string, mixed>> */
    public array $products = [
        10 => ['id' => 10, 'sku' => 'RICE', 'name' => 'Rice', 'is_active' => true],
        20 => ['id' => 20, 'sku' => 'BAG', 'name' => 'Bag', 'is_active' => true],
    ];
    /** @var array<int, array<string, mixed>> */
    public array $orders = [];
    /** @var array<int, array<string, mixed>> */
    public array $receipts = [];
    /** @var array<int, array<string, mixed>> */
    public array $payments = [];
    /** @var list<array<string, mixed>> */
    public array $ledger = [];
    private int $orderSequence = 0;
    private int $receiptSequence = 0;
    private int $paymentSequence = 0;
    private int $documentSequence = 1;

    public function upsertSupplier(array $data): array
    {
        foreach ($this->suppliers as $id => $supplier) {
            if ($supplier['code'] === $data['code']) {
                $this->suppliers[$id] = $supplier + $data;

                return ['id' => $id, 'created' => false];
            }
        }
        $id = count($this->suppliers) + 1;
        $this->suppliers[$id] = $data + ['id' => $id, 'is_active' => true];

        return ['id' => $id, 'created' => true];
    }

    public function supplier(int $supplierId): ?array
    {
        return $this->suppliers[$supplierId] ?? null;
    }

    public function product(int $productId): ?array
    {
        return $this->products[$productId] ?? null;
    }

    public function createPurchaseOrder(array $data): array
    {
        foreach ($this->orders as $order) {
            if (
                ($data['idempotency_key'] ?? null) !== null
                && $order['idempotency_key'] === $data['idempotency_key']
            ) {
                if ($order['payload_hash'] !== $data['payload_hash']) {
                    throw new ProcurementDomainException('Purchase-order reference was reused.');
                }

                return ['id' => $order['id'], 'idempotent' => true];
            }
        }
        $id = ++$this->orderSequence;
        $lineId = 0;
        $lines = [];
        foreach ($data['lines'] as $line) {
            $lines[] = $line + [
                'id' => ++$lineId,
                'purchase_order_id' => $id,
                'received_quantity_scaled' => 0,
                'received_inventory_value_irr' => 0,
                'received_tax_irr' => 0,
            ];
        }
        $this->orders[$id] = array_merge($data, [
            'id' => $id,
            'document_number' => null,
            'received_merchandise_irr' => 0,
            'received_tax_irr' => 0,
            'received_landed_cost_irr' => 0,
            'received_liability_irr' => 0,
            'paid_irr' => 0,
            'lines' => $lines,
            'supplier_floating_detail_id' => $this->suppliers[$data['supplier_id']]['floating_detail_id'],
            'supplier_payable_subsidiary_ledger_id' => $this->suppliers[$data['supplier_id']][
                'payable_subsidiary_ledger_id'
            ],
        ]);

        return ['id' => $id, 'idempotent' => false];
    }

    public function purchaseOrderForUpdate(int $purchaseOrderId): ?array
    {
        return $this->orders[$purchaseOrderId] ?? null;
    }

    public function purchaseOrder(int $purchaseOrderId): ?array
    {
        $order = $this->orders[$purchaseOrderId] ?? null;
        if ($order !== null) {
            $order['outstanding_irr'] = $order['received_liability_irr'] - $order['paid_irr'];
        }

        return $order;
    }

    public function nextDocumentNumber(string $type, int $fiscalYear): int
    {
        return $this->documentSequence++;
    }

    public function approvePurchaseOrder(
        int $purchaseOrderId,
        int $documentNumber,
        int $actorUserId,
        string $approvedAt
    ): void {
        $this->orders[$purchaseOrderId]['document_number'] = $documentNumber;
        $this->orders[$purchaseOrderId]['status'] = 'approved';
        $this->orders[$purchaseOrderId]['approved_by'] = $actorUserId;
        $this->orders[$purchaseOrderId]['approved_at'] = $approvedAt;
    }

    public function cancelPurchaseOrder(int $purchaseOrderId, int $actorUserId, string $reason): void
    {
        $this->orders[$purchaseOrderId]['status'] = 'cancelled';
    }

    public function createReceipt(array $data): array
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt['idempotency_key'] === $data['idempotency_key']) {
                if ($receipt['payload_hash'] !== $data['payload_hash']) {
                    throw new ProcurementDomainException('Receipt idempotency key was reused.');
                }

                return [
                    'id' => $receipt['id'],
                    'document_number' => $receipt['document_number'],
                    'idempotent' => true,
                    'line_ids' => [],
                ];
            }
        }
        $id = ++$this->receiptSequence;
        $documentNumber = $this->nextDocumentNumber('purchase_receipt', (int) $data['fiscal_year']);
        $data['document_number'] = $documentNumber;
        $lineIds = [];
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $lineId = ($id * 100) + $index + 1;
            $lineIds[] = $lineId;
            $lines[] = $line + ['id' => $lineId, 'inventory_batch_id' => null];
            foreach ($this->orders[$data['purchase_order_id']]['lines'] as &$orderLine) {
                if ($orderLine['id'] === $line['purchase_order_line_id']) {
                    $orderLine['received_quantity_scaled'] += $line['quantity_scaled'];
                    $orderLine['received_inventory_value_irr'] += $line['merchandise_value_irr'];
                    $orderLine['received_tax_irr'] += $line['tax_irr'];
                }
            }
            unset($orderLine);
        }
        $this->orders[$data['purchase_order_id']]['received_merchandise_irr'] += $data['merchandise_value_irr'];
        $this->orders[$data['purchase_order_id']]['received_tax_irr'] += $data['tax_irr'];
        $this->orders[$data['purchase_order_id']]['received_landed_cost_irr'] += $data['landed_cost_irr'];
        $this->orders[$data['purchase_order_id']]['received_liability_irr'] += $data['liability_irr'];
        $this->receipts[$id] = array_merge($data, [
            'id' => $id,
            'status' => 'posting',
            'accounting_status' => 'pending_configuration',
            'lines' => $lines,
        ]);
        $this->ledger[] = [
            'supplier_id' => $data['supplier_id'],
            'purchase_order_id' => $data['purchase_order_id'],
            'purchase_receipt_id' => $id,
            'charge_irr' => $data['liability_irr'],
            'payment_irr' => 0,
        ];

        return [
            'id' => $id,
            'document_number' => $documentNumber,
            'idempotent' => false,
            'line_ids' => $lineIds,
        ];
    }

    public function attachInventoryBatch(int $receiptLineId, int $inventoryBatchId): void
    {
        foreach ($this->receipts as &$receipt) {
            foreach ($receipt['lines'] as &$line) {
                if ($line['id'] === $receiptLineId) {
                    $line['inventory_batch_id'] = $inventoryBatchId;
                }
            }
            unset($line);
        }
        unset($receipt);
    }

    public function finalizeReceipt(
        int $receiptId,
        int $purchaseOrderId,
        string $purchaseOrderStatus,
        ?array $accounting
    ): void {
        $this->receipts[$receiptId]['status'] = 'posted';
        $this->receipts[$receiptId]['accounting_status'] = $accounting === null
            ? 'pending_configuration'
            : 'posted';
        $this->orders[$purchaseOrderId]['status'] = $purchaseOrderStatus;
    }

    public function paymentByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        foreach ($this->payments as $payment) {
            if ($payment['treasury_transaction_id'] === $treasuryTransactionId) {
                return $payment;
            }
        }

        return null;
    }

    public function recordPayment(
        int $purchaseOrderId,
        int $supplierId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        $id = ++$this->paymentSequence;
        $this->payments[$id] = [
            'id' => $id,
            'purchase_order_id' => $purchaseOrderId,
            'supplier_id' => $supplierId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
        ];
        $this->orders[$purchaseOrderId]['paid_irr'] += $amountIrr;
        $this->ledger[] = [
            'supplier_id' => $supplierId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_payment_id' => $id,
            'charge_irr' => 0,
            'payment_irr' => $amountIrr,
        ];

        return ['id' => $id, 'idempotent' => false];
    }

    public function suppliers(array $filters): array
    {
        return array_values($this->suppliers);
    }

    public function purchaseOrders(array $filters): array
    {
        return array_values($this->orders);
    }

    public function receipt(int $receiptId): ?array
    {
        return $this->receipts[$receiptId] ?? null;
    }

    public function receipts(array $filters): array
    {
        return array_values($this->receipts);
    }

    public function supplierStatement(int $supplierId): array
    {
        $rows = array_values(array_filter(
            $this->ledger,
            static fn (array $row): bool => $row['supplier_id'] === $supplierId
        ));
        $balance = 0;
        foreach ($rows as &$row) {
            $balance += $row['charge_irr'] - $row['payment_irr'];
            $row['balance_irr'] = $balance;
        }
        unset($row);

        return $rows;
    }
}
