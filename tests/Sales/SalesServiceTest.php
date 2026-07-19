<?php

declare(strict_types=1);

namespace Rishe\Tests\Sales;

use PHPUnit\Framework\TestCase;
use Rishe\Sales\Application\AccountingGateway;
use Rishe\Sales\Application\InventoryGateway;
use Rishe\Sales\Application\SalesRepository;
use Rishe\Sales\Application\SalesService;
use Rishe\Sales\Domain\MobileNormalizer;
use Rishe\Sales\Domain\OrderTotalCalculator;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class SalesServiceTest extends TestCase
{
    public function testOrderCreationAndPaymentCoordinateInventoryAccountingAndAudit(): void
    {
        $repository = $this->repository();
        $inventory = $this->inventory();
        $accounting = $this->accounting();
        $audit = $this->audit();
        $service = new SalesService(
            $repository,
            $inventory,
            $accounting,
            $this->transactions(),
            $audit,
            new MobileNormalizer(),
            new OrderTotalCalculator()
        );

        $order = $service->createOrder([
            'channel' => 'instagram',
            'warehouse_id' => 2,
            'customer' => ['mobile' => '۰۹۱۲۱۲۳۴۵۶۷', 'first_name' => 'Sara'],
            'promotion_code' => 'WELCOME',
            'loyalty_points' => 5,
            'lines' => [
                ['product_id' => 10, 'quantity' => '2', 'unit_price_irr' => 100000],
                ['product_id' => 11, 'quantity' => '1', 'unit_price_irr' => 200000],
            ],
            'correlation_id' => 'ig-100',
        ], 7);

        self::assertSame('+989121234567', $repository->customerPayload['mobile_normalized']);
        self::assertCount(2, $inventory->reservations);
        self::assertSame(501, $repository->reservations[101]);
        self::assertSame('sales.order.created', $audit->events[0]['event_type']);

        $paid = $service->capturePayment((int) $order['id'], [
            'provider' => 'bluebank',
            'external_payment_id' => 'PAY-100',
            'amount_irr' => $order['total_irr'],
        ], 7);

        self::assertSame([501, 502], $inventory->committed);
        self::assertSame(10030, $repository->storedOrder['cogs_irr']);
        self::assertSame(10030, $accounting->postedOrder['cogs_irr']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('sales.order.paid', $audit->events[1]['event_type']);
    }

    private function transactions(): TransactionRunner
    {
        return new class implements TransactionRunner {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    private function audit(): AuditRecorder
    {
        return new class implements AuditRecorder {
            /** @var list<array<string, mixed>> */
            public array $events = [];

            public function record(
                string $eventType,
                string $aggregateType,
                string $aggregateId,
                array $payload = [],
                ?string $correlationId = null
            ): string {
                $this->events[] = [
                    'event_type' => $eventType,
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'payload' => $payload,
                    'correlation_id' => $correlationId,
                ];

                return 'audit-' . count($this->events);
            }
        };
    }

    private function inventory(): InventoryGateway
    {
        return new class implements InventoryGateway {
            /** @var list<array<string, mixed>> */
            public array $reservations = [];

            /** @var list<int> */
            public array $committed = [];

            public function reserve(
                int $productId,
                int $warehouseId,
                int $quantityScaled,
                string $orderKey,
                int $lineId,
                int $actorUserId,
                ?string $correlationId
            ): int {
                $id = 501 + count($this->reservations);
                $this->reservations[] = compact('productId', 'warehouseId', 'quantityScaled', 'lineId');

                return $id;
            }

            public function commit(int $reservationId, int $actorUserId): array
            {
                $this->committed[] = $reservationId;

                return ['quantity_scaled' => 10000, 'cogs_irr' => $reservationId * 10];
            }

            public function release(int $reservationId, int $actorUserId): void
            {
            }
        };
    }

    private function accounting(): AccountingGateway
    {
        return new class implements AccountingGateway {
            /** @var array<string, mixed> */
            public array $postedOrder = [];

            public function postPaidOrder(array $order, int $actorUserId): ?array
            {
                $this->postedOrder = $order;

                return ['voucher_id' => 88, 'voucher_number' => 1405001];
            }
        };
    }

    private function repository(): SalesRepository
    {
        return new class implements SalesRepository {
            /** @var array<string, mixed> */
            public array $customerPayload = [];

            /** @var array<string, mixed> */
            public array $storedOrder = [];

            /** @var array<int, int> */
            public array $reservations = [];

            public function upsertCustomer(array $data): array
            {
                $this->customerPayload = $data;

                return ['id' => 7, 'created' => true, 'loyalty_balance' => 50];
            }

            public function customer(int $customerId): ?array
            {
                return ['id' => $customerId];
            }

            public function product(int $productId): ?array
            {
                return ['id' => $productId, 'sku' => 'SKU-' . $productId, 'name' => 'Product', 'is_active' => 1];
            }

            public function productByWooCommerceId(int $wooCommerceProductId): ?array
            {
                return $this->product($wooCommerceProductId);
            }

            public function activeChannelPrice(int $productId, string $channel, string $at): ?int
            {
                return 100000;
            }

            public function createChannelPrice(array $data): int
            {
                return 1;
            }

            public function createPromotion(array $data): int
            {
                return 1;
            }

            public function promotion(string $code, string $channel, int $customerId, string $at): ?array
            {
                return [
                    'id' => 9,
                    'discount_type' => 'percent',
                    'value' => 1000,
                    'max_discount_irr' => null,
                    'min_order_irr' => 0,
                ];
            }

            public function loyaltyPolicy(): array
            {
                return ['irr_per_point' => 1000, 'earn_every_irr' => 100000];
            }

            public function createOrder(array $data): array
            {
                $lines = [];
                foreach ($data['lines'] as $index => $line) {
                    $lines[] = $line + ['id' => 101 + $index, 'reservation_id' => null, 'cogs_irr' => null];
                }
                $this->storedOrder = [
                    'id' => 44,
                    'order_key' => '123e4567-e89b-42d3-a456-426614174000',
                    'status' => 'pending_payment',
                    'customer_id' => 7,
                    'warehouse_id' => 2,
                    'total_irr' => $data['totals']['total_irr'],
                    'correlation_id' => $data['correlation_id'],
                    'accounting_status' => 'not_applicable',
                    'accounting_voucher_id' => null,
                    'accounting_voucher_number' => null,
                    'lines' => $lines,
                ];

                return [
                    'id' => 44,
                    'order_key' => $this->storedOrder['order_key'],
                    'line_ids' => [101, 102],
                    'idempotent' => false,
                ];
            }

            public function orderForUpdate(int $orderId): ?array
            {
                return $this->storedOrder;
            }

            public function orderByKey(string $orderKey): ?array
            {
                return $this->storedOrder;
            }

            public function paymentForUpdate(string $provider, string $externalPaymentId): ?array
            {
                return null;
            }

            public function attachReservation(int $lineId, int $reservationId): void
            {
                $this->reservations[$lineId] = $reservationId;
                foreach ($this->storedOrder['lines'] as &$line) {
                    if ((int) $line['id'] === $lineId) {
                        $line['reservation_id'] = $reservationId;
                    }
                }
                unset($line);
            }

            public function markPaid(
                int $orderId,
                array $payment,
                array $lineCogs,
                ?array $accounting,
                int $loyaltyPointsEarned,
                int $actorUserId
            ): array {
                $this->storedOrder['status'] = 'paid';
                $this->storedOrder['cogs_irr'] = array_sum($lineCogs);

                return ['payment_id' => 77, 'loyalty_points_earned' => $loyaltyPointsEarned];
            }

            public function cancelOrder(int $orderId, int $actorUserId, string $reason): array
            {
                return ['loyalty_points_restored' => 0];
            }

            public function completeOrder(int $orderId, int $actorUserId): void
            {
            }

            public function setAccountingPosted(int $orderId, int $voucherId, int $voucherNumber): void
            {
            }

            public function orders(array $filters): array
            {
                return [$this->storedOrder];
            }

            public function order(int $orderId): ?array
            {
                return $this->storedOrder;
            }
        };
    }
}
