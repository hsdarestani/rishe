<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B\Fakes;

use Rishe\B2B\Application\B2BTreasuryGateway;

final class InMemoryB2BTreasuryGateway implements B2BTreasuryGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $transactions = [
        80 => [
            'id' => 80,
            'direction' => 'credit',
            'amount_irr' => 500000,
            'residual_amount_irr' => 500000,
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

    public function matchAccount(
        int $transactionId,
        int $accountId,
        int $amountIrr,
        int $actorUserId
    ): array {
        $this->matches[] = compact('transactionId', 'accountId', 'amountIrr', 'actorUserId');

        return ['match_id' => count($this->matches), 'residual_amount_irr' => 500000 - $amountIrr];
    }
}
