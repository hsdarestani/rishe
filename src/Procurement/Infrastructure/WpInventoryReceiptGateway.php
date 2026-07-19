<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Inventory\Application\InventoryService;
use Rishe\Procurement\Application\InventoryReceiptGateway;

final class WpInventoryReceiptGateway implements InventoryReceiptGateway
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function receive(array $data, int $actorUserId): int
    {
        return $this->inventory->receiveStock($data, $actorUserId);
    }
}
