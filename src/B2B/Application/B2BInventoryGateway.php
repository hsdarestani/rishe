<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

interface B2BInventoryGateway
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function transfer(array $data, int $actorUserId): array;

    /** @param array<string, mixed> $data @return array{reservation_id: int, quantity_scaled: int, cogs_irr: int} */
    public function consume(array $data, int $actorUserId): array;
}
