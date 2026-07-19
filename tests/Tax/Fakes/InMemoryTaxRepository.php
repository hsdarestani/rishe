<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Tax\Application\TaxRepository;

final class InMemoryTaxRepository implements TaxRepository
{
    public array $profiles = [];
    public array $mappings = [];
    public array $invoices = [];
    public array $submissions = [];
    public array $events = [];
    public array $orders = [
        10 => [
            'id' => 10,
            'status' => 'completed',
            'shipping_irr' => 0,
            'tax_irr' => 0,
            'total_irr' => 109000,
            'lines' => [[
                'id' => 100,
                'product_id' => 20,
                'name_snapshot' => 'Rice',
                'quantity_scaled' => 10000,
                'unit_price_irr' => 100000,
                'gross_irr' => 100000,
                'line_discount_irr' => 0,
                'net_irr' => 100000,
            ]],
            'payments' => [[
                'provider' => 'cash',
                'external_payment_id' => 'PAY-1',
                'amount_irr' => 109000,
                'captured_at' => '2026-07-20 10:00:00',
            ]],
        ],
    ];
    private int $profileSequence = 0;
    private int $mappingSequence = 0;
    private int $invoiceSequence = 0;
    private int $serial = 1;

    public function upsertProfile(array $data): array
    {
        foreach ($this->profiles as $id => $profile) {
            if ($profile['code'] === $data['code']) {
                $this->profiles[$id] = array_merge($profile, $data);

                return ['id' => $id, 'created' => false];
            }
        }
        $id = ++$this->profileSequence;
        $this->profiles[$id] = $data + [
            'id' => $id,
            'public_id' => 'profile-' . $id,
            'is_active' => true,
            'gateway_config' => json_decode($data['gateway_config_json'], true),
        ];

        return ['id' => $id, 'created' => true];
    }

    public function profile(int $profileId): ?array
    {
        return $this->profiles[$profileId] ?? null;
    }

    public function profiles(): array
    {
        return array_values($this->profiles);
    }

    public function upsertProductMapping(array $data): array
    {
        $key = $data['profile_id'] . ':' . $data['product_id'];
        $created = !isset($this->mappings[$key]);
        $id = $this->mappings[$key]['id'] ?? ++$this->mappingSequence;
        $this->mappings[$key] = $data + ['id' => $id, 'is_active' => true];

        return ['id' => $id, 'created' => $created];
    }

    public function productMapping(int $profileId, int $productId): ?array
    {
        return $this->mappings[$profileId . ':' . $productId] ?? null;
    }

    public function productMappings(int $profileId): array
    {
        return array_values(array_filter($this->mappings, static fn (array $row): bool => $row['profile_id'] === $profileId));
    }

    public function salesOrder(int $salesOrderId): ?array
    {
        return $this->orders[$salesOrderId] ?? null;
    }

    public function createInvoice(array $data): array
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice['idempotency_key'] === $data['idempotency_key']) {
                return ['id' => $invoice['id'], 'idempotent' => true];
            }
        }
        $id = ++$this->invoiceSequence;
        $this->invoices[$id] = [
            'id' => $id,
            'public_id' => 'invoice-' . $id,
            'profile_id' => $data['profile_id'],
            'sales_order_id' => $data['sales_order_id'],
            'source_invoice_id' => $data['source_invoice_id'],
            'derived_invoice_id' => null,
            'source_tax_number' => null,
            'subject' => strtolower($data['subject']),
            'subject_code' => $data['subject_code'],
            'status' => $data['status'],
            'invoice_type' => $data['invoice_type'],
            'invoice_pattern' => $data['invoice_pattern'],
            'settlement_method' => $data['settlement_method'],
            'buyer_type' => $data['buyer']['buyer_type'],
            'buyer_name' => $data['buyer']['name'],
            'buyer_national_id' => $data['buyer']['national_id'],
            'buyer_economic_code' => $data['buyer']['economic_code'],
            'buyer_postal_code' => $data['buyer']['postal_code'],
            'buyer_branch_code' => $data['buyer']['branch_code'],
            'seller_national_id' => $data['seller']['national_id'],
            'seller_economic_code' => $data['seller']['economic_code'],
            'seller_branch_code' => $data['seller']['branch_code'],
            'gross_irr' => $data['totals']['gross_irr'],
            'discount_irr' => $data['totals']['discount_irr'],
            'net_irr' => $data['totals']['net_irr'],
            'vat_irr' => $data['totals']['vat_irr'],
            'other_duty_irr' => 0,
            'total_irr' => $data['totals']['total_irr'],
            'cash_irr' => $data['cash_irr'],
            'credit_irr' => $data['credit_irr'],
            'idempotency_key' => $data['idempotency_key'],
            'source_hash' => $data['payload_hash'],
            'correlation_id' => $data['correlation_id'],
            'submission_attempts' => 0,
            'lines' => $data['lines'],
            'payments' => $data['payments'],
            'submissions' => [],
            'status_events' => [],
        ];

        return ['id' => $id, 'idempotent' => false];
    }

    public function invoice(int $invoiceId): ?array
    {
        return $this->invoices[$invoiceId] ?? null;
    }

    public function invoiceForUpdate(int $invoiceId): ?array
    {
        return $this->invoice($invoiceId);
    }

    public function invoices(array $filters): array
    {
        return array_values($this->invoices);
    }

    public function nextSerial(int $profileId, int $fiscalYear): int
    {
        return $this->serial++;
    }

    public function freezeInvoice(int $invoiceId, array $data): void
    {
        $this->invoices[$invoiceId] = array_merge($this->invoices[$invoiceId], $data, ['status' => 'frozen']);
    }

    public function createDerivedInvoice(int $sourceInvoiceId, string $subject, int $actorUserId): array
    {
        $source = $this->invoices[$sourceInvoiceId];
        $code = ['correction' => 2, 'cancellation' => 3, 'return' => 4][$subject];
        $result = $this->createInvoice([
            'profile_id' => $source['profile_id'],
            'sales_order_id' => $source['sales_order_id'],
            'source_invoice_id' => $sourceInvoiceId,
            'subject' => $subject,
            'subject_code' => $code,
            'status' => 'draft',
            'invoice_type' => $source['invoice_type'],
            'invoice_pattern' => $source['invoice_pattern'],
            'settlement_method' => $source['settlement_method'],
            'buyer' => [
                'buyer_type' => $source['buyer_type'], 'name' => $source['buyer_name'],
                'national_id' => $source['buyer_national_id'], 'economic_code' => $source['buyer_economic_code'],
                'postal_code' => $source['buyer_postal_code'], 'branch_code' => $source['buyer_branch_code'],
            ],
            'seller' => [
                'national_id' => $source['seller_national_id'], 'economic_code' => $source['seller_economic_code'],
                'branch_code' => $source['seller_branch_code'],
            ],
            'totals' => [
                'gross_irr' => $source['gross_irr'], 'discount_irr' => $source['discount_irr'],
                'net_irr' => $source['net_irr'], 'vat_irr' => $source['vat_irr'], 'total_irr' => $source['total_irr'],
            ],
            'cash_irr' => $source['cash_irr'], 'credit_irr' => $source['credit_irr'],
            'idempotency_key' => 'derived-' . $subject . '-' . $sourceInvoiceId,
            'payload_hash' => hash('sha256', $subject . $sourceInvoiceId),
            'correlation_id' => $source['correlation_id'], 'actor_user_id' => $actorUserId,
            'lines' => $source['lines'], 'payments' => $source['payments'],
        ]);
        $this->invoices[$result['id']]['source_tax_number'] = $source['tax_number'];

        return $result;
    }

    public function recordSubmission(int $invoiceId, array $data): int
    {
        $id = count($this->submissions) + 1;
        $this->submissions[$id] = $data + ['id' => $id, 'tax_invoice_id' => $invoiceId];
        $this->invoices[$invoiceId]['submissions'][] = $this->submissions[$id];

        return $id;
    }

    public function recordStatus(int $invoiceId, array $data): int
    {
        $id = count($this->events) + 1;
        $this->events[$id] = $data + ['id' => $id, 'tax_invoice_id' => $invoiceId];
        $this->invoices[$invoiceId]['status_events'][] = $this->events[$id];

        return $id;
    }

    public function updateInvoiceStatus(
        int $invoiceId,
        string $status,
        ?string $referenceNumber,
        ?string $uid,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $this->invoices[$invoiceId]['status'] = $status;
        $this->invoices[$invoiceId]['reference_number'] = $referenceNumber;
        $this->invoices[$invoiceId]['remote_uid'] = $uid;
        $this->invoices[$invoiceId]['last_error_code'] = $errorCode;
        $this->invoices[$invoiceId]['last_error_message'] = $errorMessage;
        $this->invoices[$invoiceId]['submission_attempts'] = count($this->invoices[$invoiceId]['submissions']);
    }

    public function markSourceDerived(int $sourceInvoiceId, string $status, int $derivedInvoiceId): void
    {
        $this->invoices[$sourceInvoiceId]['status'] = $status;
        $this->invoices[$sourceInvoiceId]['derived_invoice_id'] = $derivedInvoiceId;
    }
}
