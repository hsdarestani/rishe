<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

interface SalesPaymentBridge
{
    /** @return array<string, mixed>|null */
    public function order(int $orderId): ?array;

    /** @param array<string, mixed> $payment @return array<string, mixed> */
    public function capture(int $orderId, array $payment, int $actorUserId): array;
}
