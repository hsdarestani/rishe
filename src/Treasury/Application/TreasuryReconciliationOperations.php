<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use Rishe\Treasury\Domain\Reconciliation;
use RuntimeException;

trait TreasuryReconciliationOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function importTransaction(array $data, int $actorUserId): array
    {
        $direction = strtolower(trim((string) ($data['direction'] ?? '')));
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw new TreasuryDomainException('Treasury transaction direction must be credit or debit.');
        }
        $payload = [
            'treasury_account_id' => $this->positiveId($data['treasury_account_id'] ?? null, 'treasury_account_id'),
            'direction' => $direction,
            'amount_irr' => $this->positiveMoney($data['amount_irr'] ?? null, 'amount_irr'),
            'transaction_at' => $this->dateTime($data['transaction_at'] ?? null, 'transaction_at'),
            'value_date' => $this->nullableDate($data['value_date'] ?? null),
            'external_transaction_id' => $this->requiredReference(
                $data['external_transaction_id'] ?? null,
                'external_transaction_id'
            ),
            'reference' => $this->nullableText($data['reference'] ?? null, 191),
            'counterparty_name' => $this->nullableText($data['counterparty_name'] ?? null, 191),
            'counterparty_iban' => $this->nullableIdentifier($data['counterparty_iban'] ?? null, 34),
            'description' => $this->nullableText($data['description'] ?? null, 500),
            'source' => $this->requiredReference($data['source'] ?? 'manual', 'source', 50),
            'raw_hash' => $this->nullableHash($data['raw_hash'] ?? null),
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $this->requireActiveAccount((int) $payload['treasury_account_id']);
            $result = $this->repository->importTransaction($payload);
            if (!$result['idempotent']) {
                $this->audit->record(
                    'treasury.transaction.imported',
                    'treasury_transaction',
                    (string) $result['id'],
                    ['direction' => $payload['direction'], 'amount_irr' => $payload['amount_irr']],
                    $payload['correlation_id']
                );
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function matchTransaction(int $transactionId, array $data, int $actorUserId): array
    {
        $matchType = strtolower(trim((string) ($data['match_type'] ?? '')));
        if (!in_array($matchType, ['sales_order', 'settlement', 'purchase', 'expense', 'manual'], true)) {
            throw new TreasuryDomainException('Reconciliation match type is invalid.');
        }
        $entityId = $this->positiveId($data['entity_id'] ?? null, 'entity_id');
        $amount = $this->positiveMoney($data['amount_irr'] ?? null, 'amount_irr');
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(
            function () use ($transactionId, $matchType, $entityId, $amount, $actor): array {
                $transaction = $this->repository->transactionForUpdate(
                    $this->positiveId($transactionId, 'transaction_id')
                );
                if ($transaction === null) {
                    throw new RuntimeException('Treasury transaction not found.');
                }
                $matched = $this->repository->matchedAmountForUpdate((int) $transaction['id']);
                Reconciliation::assertMatch((int) $transaction['amount_irr'], $matched, $amount);

                $salesResult = null;
                if ($matchType === 'sales_order') {
                    if ((string) $transaction['direction'] !== 'credit') {
                        throw new TreasuryDomainException('A sales order can be matched only to a credit transaction.');
                    }
                    $order = $this->sales->order($entityId);
                    if ($order === null) {
                        throw new RuntimeException('Sales order not found.');
                    }
                    if ($amount !== (int) $order['total_irr']) {
                        throw new TreasuryDomainException('Sales-order reconciliation must equal the full order total.');
                    }
                    $salesResult = $this->sales->capture($entityId, [
                        'provider' => 'treasury_reconciliation',
                        'external_payment_id' => (string) $transaction['public_id'],
                        'amount_irr' => $amount,
                        'raw_hash' => $transaction['raw_hash'] ?? null,
                    ], $actor);
                }

                $matchId = $this->repository->createMatch([
                    'treasury_transaction_id' => (int) $transaction['id'],
                    'match_type' => $matchType,
                    'entity_id' => $entityId,
                    'amount_irr' => $amount,
                    'actor_user_id' => $actor,
                ]);
                $this->audit->record(
                    'treasury.transaction.matched',
                    'treasury_transaction',
                    (string) $transaction['id'],
                    [
                        'match_id' => $matchId,
                        'match_type' => $matchType,
                        'entity_id' => $entityId,
                        'amount_irr' => $amount,
                    ]
                );

                return [
                    'match_id' => $matchId,
                    'transaction_id' => (int) $transaction['id'],
                    'matched_amount_irr' => $matched + $amount,
                    'residual_amount_irr' => Reconciliation::residual(
                        (int) $transaction['amount_irr'],
                        $matched + $amount
                    ),
                    'sales_order' => $salesResult,
                ];
            }
        );
    }
}
