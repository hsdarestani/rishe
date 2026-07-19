<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B\Fakes;

use Rishe\B2B\Application\B2BAccountingGateway;

final class InMemoryB2BAccountingGateway implements B2BAccountingGateway
{
    /** @var list<array<string, mixed>> */
    public array $reports = [];
    /** @var list<array<string, mixed>> */
    public array $settlements = [];

    public function postSalesReport(array $report, int $actorUserId): ?array
    {
        $this->reports[] = $report + ['actor_user_id' => $actorUserId];

        return ['voucher_id' => 101, 'voucher_number' => 11];
    }

    public function postSettlement(
        array $account,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $this->settlements[] = compact('account', 'treasuryTransaction', 'amountIrr', 'actorUserId');

        return ['voucher_id' => 102, 'voucher_number' => 12];
    }
}
