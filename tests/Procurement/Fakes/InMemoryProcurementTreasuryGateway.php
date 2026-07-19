<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement\Fakes;

use Rishe\Procurement\Application\ProcurementTreasuryGateway;

final class InMemoryProcurementTreasuryGateway implements ProcurementTreasuryGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $transactions = [
        70 => [
            'id' => 70,
            'direction' => 'debit',
            'amount_irr' => 150000,
            'residual_amount_irr' => 150000,
            'treasury_account_id' => 3,
            'account' => ['id' => 3, 'subsidiary_ledger_id' => 20],
        ],
    ];
    /** @var list<array<string, int>> */
    public array $matches = [];

    public function transactionForUpdate(int $transactionId): ?array
    {
        return $this->transactions[$transactionId] ?? null;
    }

    public function matchPurchase(
        int $transactionId,
        int $purchaseOrderId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $this->matches[] = compact('transactionId', 'purchaseOrderId', 'amountIrr', 'actorUserId');

        return ['match_id' => count($this->matches), 'residual_amount_irr' => 150000 - $amountIrr];
    }
}
