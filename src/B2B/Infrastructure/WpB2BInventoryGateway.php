<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\B2B\Application\B2BInventoryGateway;
use Rishe\Inventory\Application\InventoryService;

final class WpB2BInventoryGateway implements B2BInventoryGateway
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function transfer(array $data, int $actorUserId): array
    {
        return $this->inventory->transferStock($data, $actorUserId);
    }

    public function consume(array $data, int $actorUserId): array
    {
        $reservationId = $this->inventory->reserveStock($data, $actorUserId);
        $result = $this->inventory->commitReservation($reservationId, $actorUserId);

        return [
            'reservation_id' => $reservationId,
            'quantity_scaled' => (int) $result['quantity_scaled'],
            'cogs_irr' => (int) $result['cogs_irr'],
        ];
    }
}
