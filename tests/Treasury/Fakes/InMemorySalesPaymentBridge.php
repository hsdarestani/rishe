<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury\Fakes;

use Rishe\Treasury\Application\SalesPaymentBridge;

final class InMemorySalesPaymentBridge implements SalesPaymentBridge
{
    /** @var array<int, array<string, mixed>> */
    public array $orders = [
        10 => ['id' => 10, 'status' => 'pending_payment', 'total_irr' => 250000, 'customer_id' => 7],
    ];

    /** @var list<array<string, mixed>> */
    public array $captures = [];

    public function order(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function capture(int $orderId, array $payment, int $actorUserId): array
    {
        $this->captures[] = compact('orderId', 'payment', 'actorUserId');
        $this->orders[$orderId]['status'] = 'paid';

        return $this->orders[$orderId] + ['idempotent' => false];
    }
}
