<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\LogisticsTreasuryGateway;

final class InMemoryLogisticsTreasuryGateway implements LogisticsTreasuryGateway
{
    /** @var list<array<string, int>> */
    public array $matches = [];

    public function transactionForUpdate(int $transactionId): ?array
    {
        if ($transactionId !== 70) {
            return null;
        }

        return [
            'id' => 70,
            'direction' => 'debit',
            'amount_irr' => 30000,
            'residual_amount_irr' => 30000,
            'treasury_account_id' => 3,
            'account' => ['id' => 3, 'subsidiary_ledger_id' => 20],
        ];
    }

    public function matchShipmentCost(
        int $transactionId,
        int $shipmentId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $this->matches[] = compact('transactionId', 'shipmentId', 'amountIrr', 'actorUserId');

        return ['match_id' => count($this->matches), 'residual_amount_irr' => 30000 - $amountIrr];
    }
}
