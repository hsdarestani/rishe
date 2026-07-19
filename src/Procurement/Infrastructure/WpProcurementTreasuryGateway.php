<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Application\ProcurementTreasuryGateway;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Treasury\Application\TreasuryRepository;
use Rishe\Treasury\Domain\Reconciliation;
use RuntimeException;

final class WpProcurementTreasuryGateway implements ProcurementTreasuryGateway
{
    public function __construct(
        private readonly TreasuryRepository $repository,
        private readonly AuditRecorder $audit
    ) {
    }

    public function transactionForUpdate(int $transactionId): ?array
    {
        $transaction = $this->repository->transactionForUpdate($transactionId);
        if ($transaction === null) {
            return null;
        }
        $matched = $this->repository->matchedAmountForUpdate($transactionId);
        $transaction['matched_amount_irr'] = $matched;
        $transaction['residual_amount_irr'] = Reconciliation::residual(
            (int) $transaction['amount_irr'],
            $matched
        );
        $transaction['account'] = $this->repository->account((int) $transaction['treasury_account_id']);

        return $transaction;
    }

    public function matchPurchase(
        int $transactionId,
        int $purchaseOrderId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $transaction = $this->repository->transactionForUpdate($transactionId);
        if ($transaction === null) {
            throw new RuntimeException('Treasury transaction not found.');
        }
        $matched = $this->repository->matchedAmountForUpdate($transactionId);
        Reconciliation::assertMatch((int) $transaction['amount_irr'], $matched, $amountIrr);
        $matchId = $this->repository->createMatch([
            'treasury_transaction_id' => $transactionId,
            'match_type' => 'purchase',
            'entity_id' => $purchaseOrderId,
            'amount_irr' => $amountIrr,
            'actor_user_id' => $actorUserId,
        ]);
        $this->audit->record(
            'treasury.transaction.matched',
            'treasury_transaction',
            (string) $transactionId,
            [
                'match_id' => $matchId,
                'match_type' => 'purchase',
                'entity_id' => $purchaseOrderId,
                'amount_irr' => $amountIrr,
            ]
        );

        return [
            'match_id' => $matchId,
            'transaction_id' => $transactionId,
            'matched_amount_irr' => $matched + $amountIrr,
            'residual_amount_irr' => Reconciliation::residual(
                (int) $transaction['amount_irr'],
                $matched + $amountIrr
            ),
        ];
    }
}
