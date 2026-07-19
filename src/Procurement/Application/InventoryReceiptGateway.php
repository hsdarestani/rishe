<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

interface InventoryReceiptGateway
{
    /** @param array<string, mixed> $data */
    public function receive(array $data, int $actorUserId): int;
}
