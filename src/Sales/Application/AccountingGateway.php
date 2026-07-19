<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

interface AccountingGateway
{
    /**
     * @param array<string, mixed> $order
     * @return array{voucher_id: int, voucher_number: int}|null
     */
    public function postPaidOrder(array $order, int $actorUserId): ?array;
}
