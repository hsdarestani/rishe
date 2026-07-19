<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics\Fakes;

use Rishe\Logistics\Application\LogisticsAccountingGateway;

final class InMemoryLogisticsAccountingGateway implements LogisticsAccountingGateway
{
    /** @var list<array<string, mixed>> */
    public array $settlements = [];

    public function postCarrierSettlement(
        array $shipment,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $this->settlements[] = compact('shipment', 'treasuryTransaction', 'amountIrr', 'actorUserId');

        return ['voucher_id' => 90, 'voucher_number' => 12];
    }
}
