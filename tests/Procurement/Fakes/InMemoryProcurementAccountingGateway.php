<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement\Fakes;

use Rishe\Procurement\Application\ProcurementAccountingGateway;

final class InMemoryProcurementAccountingGateway implements ProcurementAccountingGateway
{
    /** @var list<array<string, mixed>> */
    public array $receipts = [];
    /** @var list<array<string, mixed>> */
    public array $payments = [];

    public function postReceipt(array $receipt, int $actorUserId): ?array
    {
        $this->receipts[] = $receipt + ['actor_user_id' => $actorUserId];

        return ['voucher_id' => 91, 'voucher_number' => 15];
    }

    public function postPayment(
        array $purchaseOrder,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $this->payments[] = compact('purchaseOrder', 'treasuryTransaction', 'amountIrr', 'actorUserId');

        return ['voucher_id' => 92, 'voucher_number' => 16];
    }
}
