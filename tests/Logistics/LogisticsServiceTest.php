<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics;

use PHPUnit\Framework\TestCase;
use Rishe\Logistics\Application\LogisticsService;
use Rishe\Tests\Logistics\Fakes\AlwaysValidWebhookVerifier;
use Rishe\Tests\Logistics\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Logistics\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Logistics\Fakes\InMemoryCarrierGatewayRegistry;
use Rishe\Tests\Logistics\Fakes\InMemoryCarrierSecretVault;
use Rishe\Tests\Logistics\Fakes\InMemoryLogisticsAccountingGateway;
use Rishe\Tests\Logistics\Fakes\InMemoryLogisticsRepository;
use Rishe\Tests\Logistics\Fakes\InMemoryLogisticsTreasuryGateway;

final class LogisticsServiceTest extends TestCase
{
    private InMemoryLogisticsRepository $repository;
    private InMemoryLogisticsTreasuryGateway $treasury;
    private InMemoryLogisticsAccountingGateway $accounting;
    private LogisticsService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryLogisticsRepository();
        $this->treasury = new InMemoryLogisticsTreasuryGateway();
        $this->accounting = new InMemoryLogisticsAccountingGateway();
        $this->service = new LogisticsService(
            $this->repository,
            new InMemoryCarrierGatewayRegistry(),
            new InMemoryCarrierSecretVault(),
            new AlwaysValidWebhookVerifier(),
            $this->treasury,
            $this->accounting,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );
    }

    public function testFullShipmentLifecycleAndCostReconciliation(): void
    {
        $carrier = $this->service->upsertCarrier([
            'code' => 'tipax',
            'name' => 'Tipax',
            'mode' => 'sandbox',
            'base_url' => 'https://carrier.test',
            'config' => ['endpoints' => ['quote' => '/quote']],
            'credentials' => ['token' => 'secret'],
            'webhook_secret' => 'webhook-secret',
        ], 1);
        $shipment = $this->service->createShipment([
            'sales_order_id' => 1,
            'idempotency_key' => 'shipment-1',
            'sender' => $this->address('Warehouse'),
            'recipient' => $this->address('Customer'),
            'packages' => [[
                'weight_grams' => 2000,
                'length_mm' => 400,
                'width_mm' => 300,
                'height_mm' => 200,
                'quantity' => 1,
                'contents' => 'Rice',
            ]],
        ], 1);
        $shipment = $this->service->quoteShipment((int) $shipment['id'], (int) $carrier['id'], 'express', 1);
        self::assertSame('quoted', $shipment['status']);
        self::assertSame(25000, $shipment['quoted_cost_irr']);

        $shipment = $this->service->bookShipment((int) $shipment['id'], null, null, 1);
        self::assertSame('label_ready', $shipment['status']);
        self::assertSame('TRACK-100', $shipment['tracking_number']);

        $shipment = $this->service->refreshTracking((int) $shipment['id'], 1);
        self::assertSame('in_transit', $shipment['status']);

        $webhook = $this->service->processWebhook('tipax', '{"event":"delivered"}', 'valid');
        self::assertSame((int) $shipment['id'], $webhook['shipment_id']);
        self::assertSame('delivered', $this->repository->shipments[1]['status']);

        $shipment = $this->service->recordCarrierCost((int) $shipment['id'], [
            'cost_type' => 'freight',
            'amount_irr' => 25000,
            'external_cost_id' => 'cost-1',
            'incurred_at' => '2026-07-20 15:00:00',
        ], 1);
        self::assertSame(5000, $shipment['cost_variance_irr']);

        $settlement = $this->service->settleCarrierCost((int) $shipment['id'], 70, 25000, 1);
        self::assertSame(0, $settlement['unsettled_cost_irr']);
        self::assertCount(1, $this->treasury->matches);
        self::assertCount(1, $this->accounting->settlements);
    }

    /** @return array<string, string> */
    private function address(string $name): array
    {
        return [
            'name' => $name,
            'mobile' => '09120000000',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'postal_code' => '1234567890',
            'address' => 'Test street',
        ];
    }
}
