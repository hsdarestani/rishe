<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\ShipmentCostVariance;
use RuntimeException;

trait LogisticsCostOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function recordCarrierCost(int $shipmentId, array $data, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $costType = $this->code($data['cost_type'] ?? 'freight', 'cost_type');
        $allowed = ['freight', 'surcharge', 'insurance', 'cod', 'return', 'adjustment'];
        if (!in_array($costType, $allowed, true)) {
            throw new LogisticsDomainException('Carrier cost type is invalid.');
        }
        $amount = $this->positiveMoney($data['amount_irr'] ?? null, 'amount_irr');
        $externalCostId = $this->requiredText($data['external_cost_id'] ?? null, 'external_cost_id', 191);
        $incurredAt = $this->dateTime($data['incurred_at'] ?? null, 'incurred_at');

        return $this->transactions->run(function () use (
            $shipmentId,
            $data,
            $actor,
            $costType,
            $amount,
            $externalCostId,
            $incurredAt
        ): array {
            $shipment = $this->lockedShipment($shipmentId);
            if (($shipment['carrier_id'] ?? null) === null) {
                throw new LogisticsDomainException('Carrier cost requires a booked carrier.');
            }
            $result = $this->repository->recordCost((int) $shipment['id'], [
                'carrier_id' => (int) $shipment['carrier_id'],
                'cost_type' => $costType,
                'amount_irr' => $amount,
                'external_cost_id' => $externalCostId,
                'invoice_reference' => $this->nullableText($data['invoice_reference'] ?? null, 191),
                'incurred_at' => $incurredAt,
                'description' => $this->nullableText($data['description'] ?? null, 500),
                'raw_hash' => isset($data['raw_hash']) ? (string) $data['raw_hash'] : null,
                'actor_user_id' => $actor,
            ]);
            if (!$result['idempotent']) {
                $this->audit->record(
                    'logistics.carrier_cost.recorded',
                    'shipment',
                    (string) $shipment['id'],
                    [
                        'cost_id' => (int) $result['id'],
                        'cost_type' => $costType,
                        'amount_irr' => $amount,
                    ],
                    $shipment['correlation_id'] ?? null
                );
            }

            return $this->requireShipment((int) $shipment['id']);
        });
    }

    /** @return array<string, mixed> */
    public function settleCarrierCost(
        int $shipmentId,
        int $treasuryTransactionId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $actor = $this->actor($actorUserId);
        $amount = $this->positiveMoney($amountIrr, 'amount_irr');

        return $this->transactions->run(function () use (
            $shipmentId,
            $treasuryTransactionId,
            $amount,
            $actor
        ): array {
            $existing = $this->repository->settlementByTreasuryTransaction(
                $this->positiveId($treasuryTransactionId, 'treasury_transaction_id')
            );
            if ($existing !== null) {
                if ((int) $existing['shipment_id'] !== $shipmentId || (int) $existing['amount_irr'] !== $amount) {
                    throw new LogisticsDomainException(
                        'Treasury transaction is already used for another logistics settlement.'
                    );
                }

                return $existing + ['idempotent' => true];
            }

            $shipment = $this->lockedShipment($shipmentId);
            ShipmentCostVariance::assertSettlement(
                (int) $shipment['actual_cost_irr'],
                (int) $shipment['settled_cost_irr'],
                $amount
            );
            $transaction = $this->treasury->transactionForUpdate($treasuryTransactionId);
            if ($transaction === null) {
                throw new RuntimeException('Treasury transaction not found.');
            }
            if ((string) $transaction['direction'] !== 'debit') {
                throw new LogisticsDomainException('Carrier settlement requires a debit treasury transaction.');
            }
            $residual = (int) ($transaction['residual_amount_irr'] ?? $transaction['amount_irr']);
            if ($amount > $residual) {
                throw new LogisticsDomainException('Carrier settlement exceeds unmatched treasury amount.');
            }
            $match = $this->treasury->matchShipmentCost(
                (int) $transaction['id'],
                (int) $shipment['id'],
                $amount,
                $actor
            );
            $accounting = $this->accounting->postCarrierSettlement($shipment, $transaction, $amount, $actor);
            $result = $this->repository->recordSettlement(
                (int) $shipment['id'],
                (int) $transaction['id'],
                $amount,
                $accounting,
                $actor
            );
            $this->audit->record(
                'logistics.carrier_cost.settled',
                'logistics_settlement',
                (string) $result['id'],
                [
                    'shipment_id' => (int) $shipment['id'],
                    'treasury_transaction_id' => (int) $transaction['id'],
                    'match_id' => $match['match_id'] ?? null,
                    'amount_irr' => $amount,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ],
                $shipment['correlation_id'] ?? null
            );

            return [
                'id' => (int) $result['id'],
                'shipment_id' => (int) $shipment['id'],
                'amount_irr' => $amount,
                'unsettled_cost_irr' => (int) $shipment['actual_cost_irr']
                    - (int) $shipment['settled_cost_irr']
                    - $amount,
                'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                'idempotent' => false,
            ];
        });
    }
}
