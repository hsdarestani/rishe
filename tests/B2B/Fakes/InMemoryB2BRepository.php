<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B\Fakes;

use Rishe\B2B\Application\B2BRepository;
use Rishe\B2B\Domain\Exception\B2BDomainException;

final class InMemoryB2BRepository implements B2BRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $customers = [1 => ['id' => 1, 'status' => 'active']];
    /** @var array<int, array<string, mixed>> */
    public array $warehouses = [
        1 => ['id' => 1, 'type' => 'central', 'is_active' => true],
        2 => ['id' => 2, 'type' => 'consignment', 'is_active' => true],
    ];
    /** @var array<int, array<string, mixed>> */
    public array $products = [10 => ['id' => 10, 'sku' => 'RICE', 'name' => 'Rice', 'is_active' => true]];
    /** @var array<int, array<string, mixed>> */
    public array $accounts = [];
    /** @var array<int, array<string, mixed>> */
    public array $dispatches = [];
    /** @var array<int, array<string, mixed>> */
    public array $returns = [];
    /** @var array<int, array<string, mixed>> */
    public array $reports = [];
    /** @var array<int, array<string, mixed>> */
    public array $settlements = [];
    /** @var list<array<string, mixed>> */
    public array $ledger = [];
    private int $accountSequence = 0;
    private int $dispatchSequence = 0;
    private int $dispatchLineSequence = 0;
    private int $returnSequence = 0;
    private int $returnLineSequence = 0;
    private int $reportSequence = 0;
    private int $reportLineSequence = 0;
    private int $settlementSequence = 0;
    private int $documentSequence = 1;

    public function customer(int $customerId): ?array
    {
        return $this->customers[$customerId] ?? null;
    }

    public function warehouse(int $warehouseId): ?array
    {
        return $this->warehouses[$warehouseId] ?? null;
    }

    public function product(int $productId): ?array
    {
        return $this->products[$productId] ?? null;
    }

    public function upsertAccount(array $data): array
    {
        foreach ($this->accounts as $id => $account) {
            if ($account['code'] === $data['code'] || $account['customer_id'] === $data['customer_id']) {
                $this->accounts[$id] = array_merge($account, $data);

                return ['id' => $id, 'created' => false];
            }
        }
        $id = ++$this->accountSequence;
        $this->accounts[$id] = array_merge($data, [
            'id' => $id,
            'current_receivable_irr' => 0,
            'status' => 'active',
        ]);

        return ['id' => $id, 'created' => true];
    }

    public function account(int $accountId): ?array
    {
        return $this->accounts[$accountId] ?? null;
    }

    public function accountForUpdate(int $accountId): ?array
    {
        return $this->account($accountId);
    }

    public function accounts(array $filters): array
    {
        return array_values($this->accounts);
    }

    public function nextDocumentNumber(string $type, int $fiscalYear): int
    {
        return $this->documentSequence++;
    }

    public function createDispatch(array $data): array
    {
        foreach ($this->dispatches as $dispatch) {
            if ($dispatch['idempotency_key'] === $data['idempotency_key']) {
                if ($dispatch['payload_hash'] !== $data['payload_hash']) {
                    throw new B2BDomainException('Dispatch idempotency key was reused.');
                }

                return ['id' => $dispatch['id'], 'idempotent' => true, 'line_ids' => []];
            }
        }
        $id = ++$this->dispatchSequence;
        $lineIds = [];
        $lines = [];
        foreach ($data['lines'] as $line) {
            $lineId = ++$this->dispatchLineSequence;
            $lineIds[] = $lineId;
            $lines[] = array_merge($line, [
                'id' => $lineId,
                'dispatch_id' => $id,
                'sold_quantity_scaled' => 0,
                'returned_quantity_scaled' => 0,
                'transfer_group_id' => null,
            ]);
        }
        $this->dispatches[$id] = array_merge($data, [
            'id' => $id,
            'status' => 'posting',
            'lines' => $lines,
        ]);

        return ['id' => $id, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function attachDispatchTransfer(int $dispatchLineId, string $transferGroupId): void
    {
        foreach ($this->dispatches as &$dispatch) {
            foreach ($dispatch['lines'] as &$line) {
                if ($line['id'] === $dispatchLineId) {
                    $line['transfer_group_id'] = $transferGroupId;
                }
            }
            unset($line);
        }
        unset($dispatch);
    }

    public function finalizeDispatch(int $dispatchId): void
    {
        $this->dispatches[$dispatchId]['status'] = 'active';
    }

    public function dispatch(int $dispatchId): ?array
    {
        return $this->dispatches[$dispatchId] ?? null;
    }

    public function dispatchForUpdate(int $dispatchId): ?array
    {
        return $this->dispatch($dispatchId);
    }

    public function dispatches(array $filters): array
    {
        return array_values($this->dispatches);
    }

    public function createReturn(array $data): array
    {
        foreach ($this->returns as $return) {
            if ($return['idempotency_key'] === $data['idempotency_key']) {
                if ($return['payload_hash'] !== $data['payload_hash']) {
                    throw new B2BDomainException('Return idempotency key was reused.');
                }

                return ['id' => $return['id'], 'idempotent' => true, 'line_ids' => []];
            }
        }
        $id = ++$this->returnSequence;
        $lineIds = [];
        $lines = [];
        foreach ($data['lines'] as $line) {
            $lineId = ++$this->returnLineSequence;
            $lineIds[] = $lineId;
            $lines[] = array_merge($line, ['id' => $lineId, 'return_id' => $id, 'transfer_group_id' => null]);
        }
        $this->returns[$id] = array_merge($data, ['id' => $id, 'status' => 'posting', 'lines' => $lines]);

        return ['id' => $id, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function attachReturnTransfer(int $returnLineId, string $transferGroupId): void
    {
        foreach ($this->returns as &$return) {
            foreach ($return['lines'] as &$line) {
                if ($line['id'] === $returnLineId) {
                    $line['transfer_group_id'] = $transferGroupId;
                }
            }
            unset($line);
        }
        unset($return);
    }

    public function finalizeReturn(int $returnId, int $dispatchId, array $lineUpdates, string $dispatchStatus): void
    {
        foreach ($lineUpdates as $update) {
            foreach ($this->dispatches[$dispatchId]['lines'] as &$line) {
                if ($line['id'] === $update['dispatch_line_id']) {
                    $line['returned_quantity_scaled'] += $update['quantity_scaled'];
                }
            }
            unset($line);
        }
        $this->returns[$returnId]['status'] = 'posted';
        $this->dispatches[$dispatchId]['status'] = $dispatchStatus;
    }

    public function returnDocument(int $returnId): ?array
    {
        return $this->returns[$returnId] ?? null;
    }

    public function createSalesReport(array $data): array
    {
        foreach ($this->reports as $report) {
            if ($report['idempotency_key'] === $data['idempotency_key']) {
                if ($report['payload_hash'] !== $data['payload_hash']) {
                    throw new B2BDomainException('Sales report idempotency key was reused.');
                }

                return ['id' => $report['id'], 'idempotent' => true, 'line_ids' => []];
            }
        }
        $id = ++$this->reportSequence;
        $lineIds = [];
        $lines = [];
        foreach ($data['lines'] as $line) {
            $lineId = ++$this->reportLineSequence;
            $lineIds[] = $lineId;
            $lines[] = array_merge($line, [
                'id' => $lineId,
                'sales_report_id' => $id,
                'reservation_id' => null,
                'cogs_irr' => null,
                'dispatch_allocations' => [],
            ]);
        }
        $this->reports[$id] = array_merge($data, [
            'id' => $id,
            'status' => 'posting',
            'cogs_irr' => null,
            'accounting_status' => 'pending_configuration',
            'lines' => $lines,
        ]);

        return ['id' => $id, 'idempotent' => false, 'line_ids' => $lineIds];
    }

    public function allocateSoldQuantity(
        int $reportLineId,
        int $accountId,
        int $productId,
        int $quantityScaled
    ): array {
        $remaining = $quantityScaled;
        $allocations = [];
        foreach ($this->dispatches as &$dispatch) {
            if ($dispatch['account_id'] !== $accountId || !in_array($dispatch['status'], ['active', 'partially_settled'], true)) {
                continue;
            }
            foreach ($dispatch['lines'] as &$line) {
                if ($line['product_id'] !== $productId || $remaining === 0) {
                    continue;
                }
                $available = $line['quantity_scaled'] - $line['sold_quantity_scaled'] - $line['returned_quantity_scaled'];
                $allocated = min($available, $remaining);
                if ($allocated > 0) {
                    $line['sold_quantity_scaled'] += $allocated;
                    $allocations[] = ['dispatch_line_id' => $line['id'], 'quantity_scaled' => $allocated];
                    $remaining -= $allocated;
                }
            }
            unset($line);
            $open = false;
            foreach ($dispatch['lines'] as $line) {
                if ($line['sold_quantity_scaled'] + $line['returned_quantity_scaled'] < $line['quantity_scaled']) {
                    $open = true;
                    break;
                }
            }
            $dispatch['status'] = $open ? 'partially_settled' : 'closed';
        }
        unset($dispatch);
        if ($remaining > 0) {
            throw new B2BDomainException('Reported sale exceeds dispatched quantity.');
        }
        foreach ($this->reports as &$report) {
            foreach ($report['lines'] as &$line) {
                if ($line['id'] === $reportLineId) {
                    $line['dispatch_allocations'] = $allocations;
                }
            }
            unset($line);
        }
        unset($report);

        return $allocations;
    }

    public function attachSalesConsumption(int $reportLineId, int $reservationId, int $cogsIrr): void
    {
        foreach ($this->reports as &$report) {
            foreach ($report['lines'] as &$line) {
                if ($line['id'] === $reportLineId) {
                    $line['reservation_id'] = $reservationId;
                    $line['cogs_irr'] = $cogsIrr;
                }
            }
            unset($line);
        }
        unset($report);
    }

    public function finalizeSalesReport(
        int $reportId,
        int $accountId,
        int $receivableIrr,
        int $cogsIrr,
        string $dueDate,
        ?array $accounting
    ): void {
        $this->reports[$reportId]['status'] = 'posted';
        $this->reports[$reportId]['cogs_irr'] = $cogsIrr;
        $this->reports[$reportId]['due_date'] = $dueDate;
        $this->reports[$reportId]['accounting_status'] = $accounting === null ? 'pending_configuration' : 'posted';
        $this->accounts[$accountId]['current_receivable_irr'] += $receivableIrr;
        $this->ledger[] = [
            'account_id' => $accountId,
            'sales_report_id' => $reportId,
            'settlement_id' => null,
            'charge_irr' => $receivableIrr,
            'payment_irr' => 0,
        ];
    }

    public function salesReport(int $reportId): ?array
    {
        return $this->reports[$reportId] ?? null;
    }

    public function salesReports(array $filters): array
    {
        return array_values($this->reports);
    }

    public function settlementByTreasuryTransaction(int $treasuryTransactionId): ?array
    {
        foreach ($this->settlements as $settlement) {
            if ($settlement['treasury_transaction_id'] === $treasuryTransactionId) {
                return $settlement;
            }
        }

        return null;
    }

    public function recordSettlement(
        int $accountId,
        int $treasuryTransactionId,
        int $amountIrr,
        ?array $accounting,
        int $actorUserId
    ): array {
        $id = ++$this->settlementSequence;
        $this->settlements[$id] = [
            'id' => $id,
            'account_id' => $accountId,
            'treasury_transaction_id' => $treasuryTransactionId,
            'amount_irr' => $amountIrr,
            'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
        ];
        $this->accounts[$accountId]['current_receivable_irr'] -= $amountIrr;
        $this->ledger[] = [
            'account_id' => $accountId,
            'sales_report_id' => null,
            'settlement_id' => $id,
            'charge_irr' => 0,
            'payment_irr' => $amountIrr,
        ];

        return ['id' => $id, 'idempotent' => false];
    }

    public function statement(int $accountId): array
    {
        $rows = array_values(array_filter(
            $this->ledger,
            static fn (array $row): bool => $row['account_id'] === $accountId
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
