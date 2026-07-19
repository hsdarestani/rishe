<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Rishe\Tax\Application\TaxService;
use Rishe\Tax\Domain\TaxInvoiceNumberGenerator;
use Rishe\Tax\Domain\TaxTotals;
use Rishe\Tests\Tax\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Tax\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Tax\Fakes\InMemoryTaxGateway;
use Rishe\Tests\Tax\Fakes\InMemoryTaxGatewayRegistry;
use Rishe\Tests\Tax\Fakes\InMemoryTaxRepository;
use Rishe\Tests\Tax\Fakes\InMemoryTaxSecretVault;
use Rishe\Tests\Tax\Fakes\InMemoryTaxSigner;

final class TaxServiceTest extends TestCase
{
    private InMemoryTaxRepository $repository;
    private InMemoryTaxGateway $gateway;
    private TaxService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryTaxRepository();
        $this->gateway = new InMemoryTaxGateway();
        $vault = new InMemoryTaxSecretVault();
        $this->service = new TaxService(
            $this->repository,
            new InMemoryTaxGatewayRegistry($this->gateway),
            $vault,
            new InMemoryTaxSigner(),
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder(),
            new TaxInvoiceNumberGenerator(),
            new TaxTotals()
        );
        $this->service->upsertProfile([
            'code' => 'MAIN',
            'name' => 'Main Tax Profile',
            'taxpayer_type' => 2,
            'national_id' => '10101010101',
            'economic_code' => '411111111111',
            'fiscal_memory_id' => 'ABC123',
            'default_invoice_type' => 1,
            'default_pattern' => 1,
            'gateway_type' => 'http_json',
            'gateway_config' => [],
            'credentials' => [],
            'private_key_pem' => 'private-key',
        ], 1);
        $this->service->upsertProductMapping([
            'profile_id' => 1,
            'product_id' => 20,
            'tax_product_id' => '2710000000001',
            'measurement_unit' => '1627',
            'vat_rate_basis_points' => 900,
        ], 1);
    }

    public function testCreatesFreezesSignsAndSubmitsInvoice(): void
    {
        $invoice = $this->service->createFromSalesOrder([
            'profile_id' => 1,
            'sales_order_id' => 10,
            'buyer_type' => 1,
            'buyer' => [
                'name' => 'Buyer',
                'national_id' => '0012345678',
                'economic_code' => '411111111112',
                'postal_code' => '1234567890',
            ],
            'idempotency_key' => 'tax-order-10',
        ], 1);
        self::assertSame(109000, $invoice['total_irr']);

        $frozen = $this->service->freeze((int) $invoice['id'], 1);
        self::assertSame('frozen', $frozen['status']);
        self::assertSame(22, strlen($frozen['tax_number']));
        self::assertNotEmpty($frozen['signature']);

        $submitted = $this->service->submit((int) $invoice['id'], 1);
        self::assertSame('accepted', $submitted['status']);
        self::assertSame('REF-1', $submitted['reference_number']);
        self::assertCount(1, $this->gateway->submitted);
    }

    public function testAcceptedInvoiceCanCreateCancellationWithoutMutation(): void
    {
        $invoice = $this->service->createFromSalesOrder([
            'profile_id' => 1,
            'sales_order_id' => 10,
            'buyer_type' => 2,
            'buyer' => [],
            'idempotency_key' => 'tax-order-10',
        ], 1);
        $this->service->freeze((int) $invoice['id'], 1);
        $this->service->submit((int) $invoice['id'], 1);

        $cancel = $this->service->derive((int) $invoice['id'], 'cancellation', 1);

        self::assertSame('draft', $cancel['status']);
        self::assertSame(3, $cancel['subject_code']);
        self::assertSame($this->repository->invoices[1]['total_irr'], $cancel['total_irr']);
    }
}
