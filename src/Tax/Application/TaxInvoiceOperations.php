<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Tax\Domain\Exception\TaxDomainException;
use Rishe\Tax\Domain\TaxInvoiceStatus;
use Rishe\Tax\Domain\TaxInvoiceSubject;

trait TaxInvoiceOperations
{
    public function createFromSalesOrder(array $data, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $profileId = $this->positiveId($data['profile_id'] ?? null, 'profile_id');
        $salesOrderId = $this->positiveId($data['sales_order_id'] ?? null, 'sales_order_id');
        $profile = $this->requireProfile($profileId);
        $order = $this->repository->salesOrder($salesOrderId);
        if ($order === null || (string) ($order['status'] ?? '') === 'cancelled') {
            throw new TaxDomainException('Sales order is missing or cancelled.');
        }
        $buyer = $this->buyerSnapshot($data['buyer'] ?? [], (int) ($data['buyer_type'] ?? 2));
        $lines = [];
        foreach ($order['lines'] as $orderLine) {
            $mapping = $this->repository->productMapping($profileId, (int) $orderLine['product_id']);
            if ($mapping === null || !(bool) ($mapping['is_active'] ?? false)) {
                throw new TaxDomainException('Tax product mapping is missing for product ' . $orderLine['product_id'] . '.');
            }
            $gross = (int) $orderLine['gross_irr'];
            $discount = (int) $orderLine['line_discount_irr'];
            $net = $gross - $discount;
            $vatRate = (int) $mapping['vat_rate_basis_points'];
            $vat = intdiv(($net * $vatRate) + 5000, 10000);
            $lines[] = [
                'sales_order_line_id' => (int) $orderLine['id'],
                'product_id' => (int) $orderLine['product_id'],
                'tax_product_id' => (string) $mapping['tax_product_id'],
                'description' => (string) $orderLine['name_snapshot'],
                'measurement_unit' => (string) $mapping['measurement_unit'],
                'quantity_scaled' => (int) $orderLine['quantity_scaled'],
                'unit_price_irr' => (int) $orderLine['unit_price_irr'],
                'gross_irr' => $gross,
                'discount_irr' => $discount,
                'net_irr' => $net,
                'vat_rate_basis_points' => $vatRate,
                'vat_irr' => $vat,
                'other_duty_irr' => 0,
                'total_irr' => $net + $vat,
            ];
        }
        $totals = $this->totals->calculate($lines);
        $subject = TaxInvoiceSubject::ORIGINAL;
        $idempotencyKey = $this->requiredText($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $payloadHash = hash('sha256', json_encode([
            'profile_id' => $profileId,
            'sales_order_id' => $salesOrderId,
            'buyer' => $buyer,
            'lines' => $lines,
            'subject' => $subject->value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->transactions->run(function () use (
            $profile,
            $profileId,
            $order,
            $buyer,
            $lines,
            $totals,
            $subject,
            $idempotencyKey,
            $payloadHash,
            $data,
            $actor
        ): array {
            $result = $this->repository->createInvoice([
                'profile_id' => $profileId,
                'sales_order_id' => (int) $order['id'],
                'source_invoice_id' => null,
                'subject' => $subject->name,
                'subject_code' => $subject->value,
                'status' => TaxInvoiceStatus::DRAFT->value,
                'invoice_type' => (int) ($data['invoice_type'] ?? $profile['default_invoice_type']),
                'invoice_pattern' => (int) ($data['invoice_pattern'] ?? $profile['default_pattern']),
                'settlement_method' => (int) ($data['settlement_method'] ?? 1),
                'buyer' => $buyer,
                'seller' => [
                    'national_id' => $profile['national_id'],
                    'economic_code' => $profile['economic_code'],
                    'branch_code' => $profile['branch_code'],
                ],
                'totals' => $totals,
                'cash_irr' => $this->nonNegativeMoney($data['cash_irr'] ?? $totals['total_irr'], 'cash_irr'),
                'credit_irr' => $this->nonNegativeMoney($data['credit_irr'] ?? 0, 'credit_irr'),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
                'actor_user_id' => $actor,
                'lines' => $lines,
                'payments' => $order['payments'] ?? [],
            ]);
            if (!$result['idempotent']) {
                $this->audit->record('tax.invoice.created', 'tax_invoice', (string) $result['id'], [
                    'sales_order_id' => (int) $order['id'],
                    'profile_id' => $profileId,
                    'total_irr' => $totals['total_irr'],
                ]);
            }

            return $this->requireInvoice((int) $result['id']);
        });
    }

    public function freeze(int $invoiceId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($invoiceId, $actor): array {
            $invoice = $this->repository->invoiceForUpdate($this->positiveId($invoiceId, 'invoice_id'));
            if ($invoice === null) {
                throw new TaxDomainException('Tax invoice not found.');
            }
            $status = TaxInvoiceStatus::tryFrom((string) $invoice['status']);
            if ($status === TaxInvoiceStatus::FROZEN || $status === TaxInvoiceStatus::SUBMITTED) {
                return $this->requireInvoice((int) $invoice['id']);
            }
            if ($status === null) {
                throw new TaxDomainException('Tax invoice status is invalid.');
            }
            $status->assertCanFreeze();
            $profile = $this->requireProfile((int) $invoice['profile_id']);
            $issuedAt = gmdate('Y-m-d H:i:s');
            $fiscalYear = (int) gmdate('Y');
            $serial = $this->repository->nextSerial((int) $profile['id'], $fiscalYear);
            $taxNumber = $this->numbers->generate(
                (string) $profile['fiscal_memory_id'],
                strtotime($issuedAt),
                $serial
            );
            $payload = $this->canonicalPayload($invoice, $profile, $taxNumber, $serial, $issuedAt);
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $privateKey = $this->vault->open((string) $profile['private_key_ciphertext']);
            $signature = $this->signer->sign($payloadJson, $privateKey, $profile['key_id'] ?? null);
            $this->repository->freezeInvoice((int) $invoice['id'], [
                'fiscal_year' => $fiscalYear,
                'internal_serial' => $serial,
                'tax_number' => $taxNumber,
                'issued_at' => $issuedAt,
                'payload_json' => $payloadJson,
                'payload_sha256' => hash('sha256', $payloadJson),
                'signature' => $signature,
                'frozen_by' => $actor,
            ]);
            $this->audit->record('tax.invoice.frozen', 'tax_invoice', (string) $invoice['id'], [
                'tax_number' => $taxNumber,
                'internal_serial' => $serial,
            ]);

            return $this->requireInvoice((int) $invoice['id']);
        });
    }

    public function derive(int $sourceInvoiceId, string $subject, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);
        $subjectEnum = TaxInvoiceSubject::tryFrom(match (strtolower($subject)) {
            'correction' => 2,
            'cancellation' => 3,
            'return' => 4,
            default => 0,
        });
        if ($subjectEnum === null || $subjectEnum === TaxInvoiceSubject::ORIGINAL) {
            throw new TaxDomainException('Derived invoice subject is invalid.');
        }

        return $this->transactions->run(function () use ($sourceInvoiceId, $subjectEnum, $actor): array {
            $source = $this->repository->invoiceForUpdate($this->positiveId($sourceInvoiceId, 'source_invoice_id'));
            if ($source === null || empty($source['tax_number'])) {
                throw new TaxDomainException('Source tax invoice is missing or has not been frozen.');
            }
            $status = TaxInvoiceStatus::tryFrom((string) $source['status']);
            if ($status === null) {
                throw new TaxDomainException('Source tax invoice status is invalid.');
            }
            $status->assertCanDerive();
            $result = $this->repository->createDerivedInvoice(
                (int) $source['id'],
                strtolower($subjectEnum->name),
                $actor
            );
            $this->audit->record('tax.invoice.derived', 'tax_invoice', (string) $result['id'], [
                'source_invoice_id' => (int) $source['id'],
                'subject' => strtolower($subjectEnum->name),
            ]);

            return $this->requireInvoice((int) $result['id']);
        });
    }

    public function invoice(int $invoiceId): array
    {
        return $this->requireInvoice($this->positiveId($invoiceId, 'invoice_id'));
    }

    public function invoices(array $filters = []): array
    {
        return $this->repository->invoices([
            'profile_id' => isset($filters['profile_id']) ? (int) $filters['profile_id'] : null,
            'sales_order_id' => isset($filters['sales_order_id']) ? (int) $filters['sales_order_id'] : null,
            'status' => $this->nullableText($filters['status'] ?? null, 30),
            'subject' => $this->nullableText($filters['subject'] ?? null, 30),
        ]);
    }

    private function buyerSnapshot(mixed $buyer, int $buyerType): array
    {
        $buyer = $this->jsonObject($buyer, 'buyer');
        if (!in_array($buyerType, [1, 2, 3, 4], true)) {
            throw new TaxDomainException('Buyer type is invalid.');
        }
        $identity = $this->nullableText($buyer['national_id'] ?? null, 30);
        $economic = $this->nullableText($buyer['economic_code'] ?? null, 30);
        if ($buyerType !== 2 && $identity === null && $economic === null) {
            throw new TaxDomainException('Buyer identity is required for this invoice type.');
        }

        return [
            'buyer_type' => $buyerType,
            'name' => $this->nullableText($buyer['name'] ?? null, 191),
            'national_id' => $identity,
            'economic_code' => $economic,
            'postal_code' => $this->nullableText($buyer['postal_code'] ?? null, 20),
            'branch_code' => $this->nullableText($buyer['branch_code'] ?? null, 20),
        ];
    }

    private function canonicalPayload(array $invoice, array $profile, string $taxNumber, int $serial, string $issuedAt): array
    {
        $issuedMs = strtotime($issuedAt) * 1000;
        $header = [
            'taxid' => $taxNumber,
            'indatim' => $issuedMs,
            'indati2m' => $issuedMs,
            'inty' => (int) $invoice['invoice_type'],
            'inno' => (string) $serial,
            'irtaxid' => $invoice['source_tax_number'] ?? null,
            'inp' => (int) $invoice['invoice_pattern'],
            'ins' => (int) $invoice['subject_code'],
            'tins' => (string) $profile['economic_code'],
            'tob' => (int) $invoice['buyer_type'],
            'bid' => $invoice['buyer_national_id'],
            'tinb' => $invoice['buyer_economic_code'],
            'sbc' => $profile['branch_code'],
            'bpc' => $invoice['buyer_postal_code'],
            'bbc' => $invoice['buyer_branch_code'],
            'tprdis' => (int) $invoice['gross_irr'],
            'tdis' => (int) $invoice['discount_irr'],
            'tadis' => (int) $invoice['net_irr'],
            'tvam' => (int) $invoice['vat_irr'],
            'todam' => (int) $invoice['other_duty_irr'],
            'tbill' => (int) $invoice['total_irr'],
            'setm' => (int) $invoice['settlement_method'],
            'cap' => (int) $invoice['cash_irr'],
            'insp' => (int) $invoice['credit_irr'],
        ];
        $body = array_map(static function (array $line): array {
            return [
                'sstid' => $line['tax_product_id'],
                'sstt' => $line['description'],
                'mu' => $line['measurement_unit'],
                'am' => number_format((int) $line['quantity_scaled'] / Quantity::SCALE, 4, '.', ''),
                'fee' => (int) $line['unit_price_irr'],
                'prdis' => (int) $line['gross_irr'],
                'dis' => (int) $line['discount_irr'],
                'adis' => (int) $line['net_irr'],
                'vra' => (int) $line['vat_rate_basis_points'] / 100,
                'vam' => (int) $line['vat_irr'],
                'odam' => (int) $line['other_duty_irr'],
                'tsstam' => (int) $line['total_irr'],
            ];
        }, $invoice['lines']);
        $payments = array_map(static fn (array $payment): array => [
            'trn' => $payment['external_payment_id'] ?? null,
            'pdt' => isset($payment['captured_at']) ? strtotime((string) $payment['captured_at']) * 1000 : null,
            'pv' => (int) ($payment['amount_irr'] ?? 0),
        ], $invoice['payments']);

        return ['header' => $header, 'body' => $body, 'payments' => $payments];
    }
}
