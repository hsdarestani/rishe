<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use Rishe\Procurement\Domain\PurchaseOrderStatus;
use RuntimeException;

trait ProcurementPaymentOperations
{
    /** @return array<string, mixed> */
    public function registerSupplierPayment(
        int $purchaseOrderId,
        int $treasuryTransactionId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $actor = $this->actor($actorUserId);
        $amount = $this->positiveMoney($amountIrr, 'amount_irr');

        return $this->transactions->run(function () use (
            $purchaseOrderId,
            $treasuryTransactionId,
            $amount,
            $actor
        ): array {
            $existing = $this->repository->paymentByTreasuryTransaction(
                $this->positiveId($treasuryTransactionId, 'treasury_transaction_id')
            );
            if ($existing !== null) {
                if (
                    (int) $existing['purchase_order_id'] !== $purchaseOrderId
                    || (int) $existing['amount_irr'] !== $amount
                ) {
                    throw new ProcurementDomainException(
                        'Treasury transaction is already used for another supplier payment.'
                    );
                }

                return $existing + ['idempotent' => true];
            }

            $order = $this->repository->purchaseOrderForUpdate(
                $this->positiveId($purchaseOrderId, 'purchase_order_id')
            );
            if ($order === null) {
                throw new RuntimeException('Purchase order not found.');
            }
            $status = PurchaseOrderStatus::tryFrom((string) $order['status']);
            if ($status === null || !in_array($status, [
                PurchaseOrderStatus::PARTIALLY_RECEIVED,
                PurchaseOrderStatus::RECEIVED,
            ], true)) {
                throw new ProcurementDomainException('Supplier payments require a received purchase order.');
            }
            $outstanding = (int) $order['received_liability_irr'] - (int) $order['paid_irr'];
            if ($outstanding < 1 || $amount > $outstanding) {
                throw new ProcurementDomainException('Supplier payment exceeds the outstanding purchase liability.');
            }

            $transaction = $this->treasury->transactionForUpdate($treasuryTransactionId);
            if ($transaction === null) {
                throw new RuntimeException('Treasury transaction not found.');
            }
            if ((string) $transaction['direction'] !== 'debit') {
                throw new ProcurementDomainException('Supplier payment requires a debit treasury transaction.');
            }
            if ($amount > (int) ($transaction['residual_amount_irr'] ?? $transaction['amount_irr'])) {
                throw new ProcurementDomainException('Supplier payment exceeds the unmatched treasury amount.');
            }

            $match = $this->treasury->matchPurchase(
                (int) $transaction['id'],
                (int) $order['id'],
                $amount,
                $actor
            );
            $accounting = $this->accounting->postPayment($order, $transaction, $amount, $actor);
            $result = $this->repository->recordPayment(
                (int) $order['id'],
                (int) $order['supplier_id'],
                (int) $transaction['id'],
                $amount,
                $accounting,
                $actor
            );
            $this->audit->record(
                'procurement.supplier_payment.recorded',
                'purchase_payment',
                (string) $result['id'],
                [
                    'purchase_order_id' => (int) $order['id'],
                    'treasury_transaction_id' => (int) $transaction['id'],
                    'match_id' => $match['match_id'] ?? null,
                    'amount_irr' => $amount,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ],
                $this->nullableText($order['correlation_id'] ?? null, 64)
            );

            return [
                'id' => (int) $result['id'],
                'purchase_order_id' => (int) $order['id'],
                'treasury_transaction_id' => (int) $transaction['id'],
                'amount_irr' => $amount,
                'outstanding_irr' => $outstanding - $amount,
                'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                'idempotent' => false,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function supplierStatement(int $supplierId): array
    {
        $this->requireSupplier($this->positiveId($supplierId, 'supplier_id'));

        return $this->repository->supplierStatement($supplierId);
    }
}
