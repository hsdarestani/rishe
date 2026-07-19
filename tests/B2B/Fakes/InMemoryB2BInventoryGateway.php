<?php

declare(strict_types=1);

namespace Rishe\Tests\B2B\Fakes;

use Rishe\B2B\Application\B2BInventoryGateway;
use Rishe\Inventory\Domain\Quantity;

final class InMemoryB2BInventoryGateway implements B2BInventoryGateway
{
    /** @var list<array<string, mixed>> */
    public array $transfers = [];
    /** @var list<array<string, mixed>> */
    public array $consumptions = [];

    public function transfer(array $data, int $actorUserId): array
    {
        $this->transfers[] = $data + ['actor_user_id' => $actorUserId];

        return [
            'quantity_scaled' => Quantity::fromInput($data['quantity'])->scaled(),
            'inventory_value_irr' => 0,
            'transfer_group_id' => 'transfer-' . count($this->transfers),
        ];
    }

    public function consume(array $data, int $actorUserId): array
    {
        $quantity = Quantity::fromInput($data['quantity'])->scaled();
        $this->consumptions[] = $data + ['actor_user_id' => $actorUserId];

        return [
            'reservation_id' => 100 + count($this->consumptions),
            'quantity_scaled' => $quantity,
            'cogs_irr' => intdiv($quantity * 3000, Quantity::SCALE),
        ];
    }
}
