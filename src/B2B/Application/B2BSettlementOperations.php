<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait B2BSettlementOperations
{
    /** @return array<string, mixed> */
    public function settleAccount(
        int $accountId,
        int $treasuryTransactionId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $actor = $this->actor($actorUserId);
        $amount = $this->positiveMoney($amountIrr, 'amount_irr');

        return $this->transactions->run(function () use (
            $accountId,
            $treasuryTransactionId,
            $amount,
            $actor
        ): array {
            $existing = $this->repository->settlementByTreasuryTransaction(
                $this->positiveId($treasuryTransactionId, 'treasury_transaction_id')
            );
            if ($existing !== null) {
                if ((int) $existing['account_id'] !== $accountId || (int) $existing['amount_irr'] !== $amount) {
                    throw new B2BDomainException('Treasury transaction is already used for another B2B settlement.');
                }

                return $existing + ['idempotent' => true];
            }

            $account = $this->requireAccount($this->positiveId($accountId, 'account_id'), true);
            if ($amount > (int) $account['current_receivable_irr']) {
                throw new B2BDomainException('Settlement exceeds the current B2B receivable.');
            }
            $transaction = $this->treasury->transactionForUpdate($treasuryTransactionId);
            if ($transaction === null) {
                throw new RuntimeException('Treasury transaction not found.');
            }
            if ((string) $transaction['direction'] !== 'credit') {
                throw new B2BDomainException('B2B settlement requires a credit treasury transaction.');
            }
            if ($amount > (int) ($transaction['residual_amount_irr'] ?? $transaction['amount_irr'])) {
                throw new B2BDomainException('Settlement exceeds the unmatched treasury amount.');
            }

            $match = $this->treasury->matchAccount(
                (int) $transaction['id'],
                (int) $account['id'],
                $amount,
                $actor
            );
            $accounting = $this->accounting->postSettlement($account, $transaction, $amount, $actor);
            $result = $this->repository->recordSettlement(
                (int) $account['id'],
                (int) $transaction['id'],
                $amount,
                $accounting,
                $actor
            );
            $this->audit->record(
                'b2b.settlement.recorded',
                'b2b_settlement',
                (string) $result['id'],
                [
                    'account_id' => (int) $account['id'],
                    'treasury_transaction_id' => (int) $transaction['id'],
                    'match_id' => $match['match_id'] ?? null,
                    'amount_irr' => $amount,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ]
            );

            return [
                'id' => (int) $result['id'],
                'account_id' => (int) $account['id'],
                'treasury_transaction_id' => (int) $transaction['id'],
                'amount_irr' => $amount,
                'outstanding_irr' => (int) $account['current_receivable_irr'] - $amount,
                'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                'idempotent' => false,
            ];
        });
    }
}
