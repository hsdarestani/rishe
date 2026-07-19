<?php

declare(strict_types=1);

namespace Rishe\Tests\Procurement\Fakes;

use Rishe\Procurement\Application\InventoryReceiptGateway;

final class InMemoryInventoryReceiptGateway implements InventoryReceiptGateway
{
    /** @var list<array<string, mixed>> */
    public array $receipts = [];

    public function receive(array $data, int $actorUserId): int
    {
        $this->receipts[] = $data + ['actor_user_id' => $actorUserId];

        return 1000 + count($this->receipts);
    }
}
