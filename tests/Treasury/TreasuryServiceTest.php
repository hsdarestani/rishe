<?php

declare(strict_types=1);

namespace Rishe\Tests\Treasury;

use PHPUnit\Framework\TestCase;
use Rishe\Tests\Treasury\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Treasury\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Treasury\Fakes\InMemoryPaymentLinkGateway;
use Rishe\Tests\Treasury\Fakes\InMemorySalesPaymentBridge;
use Rishe\Tests\Treasury\Fakes\InMemoryTreasuryRepository;
use Rishe\Treasury\Application\TreasuryService;

final class TreasuryServiceTest extends TestCase
{
    private InMemoryTreasuryRepository $repository;
    private InMemoryPaymentLinkGateway $gateway;
    private InMemorySalesPaymentBridge $sales;
    private TreasuryService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryTreasuryRepository();
        $this->gateway = new InMemoryPaymentLinkGateway();
        $this->sales = new InMemorySalesPaymentBridge();
        $this->service = new TreasuryService(
            $this->repository,
            $this->gateway,
            $this->sales,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );
    }

    public function testPaymentLinkUsesImmutableSalesOrderTotal(): void
    {
        $link = $this->service->createPaymentLink([
            'provider' => 'blue_business',
            'sales_order_id' => 10,
            'idempotency_key' => 'order-10-link-1',
            'expires_at' => '2026-07-20 18:00:00',
            'callback_url' => 'https://example.test/callback',
        ], 1);

        self::assertSame('active', $link['status']);
        self::assertSame(250000, $link['amount_irr']);
        self::assertSame('https://pay.example/link-1', $link['payment_url']);
    }

    public function testPaidCallbackCreatesTransactionAndCapturesSalesOrder(): void
    {
        $link = $this->service->createPaymentLink([
            'provider' => 'blue_business',
            'sales_order_id' => 10,
            'idempotency_key' => 'order-10-link-1',
            'callback_url' => 'https://example.test/callback',
        ], 1);

        $result = $this->service->handleCallback('blue_business', '{}', [], 1);

        self::assertSame('paid', $result['status']);
        self::assertCount(1, $this->repository->transactions);
        self::assertCount(1, $this->repository->matches);
        self::assertCount(1, $this->sales->captures);
        self::assertSame($link['id'], $result['id']);
    }
}
