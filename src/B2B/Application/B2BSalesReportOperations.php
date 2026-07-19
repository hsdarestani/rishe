<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use Rishe\Inventory\Domain\Quantity;
use RuntimeException;

trait B2BSalesReportOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function postSalesReport(array $data, int $actorUserId): array
    {
        $accountId = $this->positiveId($data['account_id'] ?? null, 'account_id');
        $fiscalYear = $this->fiscalYear($data['fiscal_year'] ?? null);
        $reportedAt = $this->dateTime($data['reported_at'] ?? null, 'reported_at');
        $idempotencyKey = $this->requiredReference($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $rawLines = $data['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            throw new B2BDomainException('An agent sales report requires at least one line.');
        }
        $actor = $this->actor($actorUserId);
        $correlationId = $this->nullableText($data['correlation_id'] ?? null, 64);

        return $this->transactions->run(function () use (
            $accountId,
            $fiscalYear,
            $reportedAt,
            $idempotencyKey,
            $rawLines,
            $actor,
            $correlationId,
            $data
        ): array {
            $account = $this->requireAccount($accountId, true);
            if (!in_array((string) $account['account_type'], ['consignment', 'hybrid'], true)) {
                throw new B2BDomainException('Account does not support consignment sales reports.');
            }

            $lines = [];
            $seen = [];
            $grossTotal = 0;
            $commissionTotal = 0;
            $receivableTotal = 0;
            foreach (array_values($rawLines) as $rawLine) {
                if (!is_array($rawLine)) {
                    throw new B2BDomainException('Sales-report line must be an object.');
                }
                $productId = $this->positiveId($rawLine['product_id'] ?? null, 'product_id');
                if (isset($seen[$productId])) {
                    throw new B2BDomainException('A product cannot appear twice in one sales report.');
                }
                $seen[$productId] = true;
                $product = $this->repository->product($productId);
                if ($product === null || !(bool) ($product['is_active'] ?? false)) {
                    throw new B2BDomainException('Sales-report product is missing or inactive.');
                }
                $quantity = $this->quantity($rawLine['quantity'] ?? null);
                $unitPrice = $this->positiveMoney($rawLine['unit_price_irr'] ?? null, 'unit_price_irr');
                $rate = array_key_exists('commission_rate_bps', $rawLine)
                    ? $this->rateBps($rawLine['commission_rate_bps'])
                    : (int) $account['commission_rate_bps'];
                $gross = intdiv($quantity->scaled() * $unitPrice, Quantity::SCALE);
                $commission = $this->commissions->calculate($gross, $rate);
                $receivable = $gross - $commission;
                $lines[] = [
                    'product_id' => $productId,
                    'product_name' => (string) $product['name'],
                    'sku' => (string) $product['sku'],
                    'quantity_scaled' => $quantity->scaled(),
                    'unit_price_irr' => $unitPrice,
                    'gross_irr' => $gross,
                    'commission_rate_bps' => $rate,
                    'commission_irr' => $commission,
                    'receivable_irr' => $receivable,
                ];
                $grossTotal += $gross;
                $commissionTotal += $commission;
                $receivableTotal += $receivable;
            }
            $this->credit->assertCanCharge(
                (int) $account['current_receivable_irr'],
                $receivableTotal,
                (int) $account['credit_limit_irr']
            );

            $commercial = [
                'account_id' => $accountId,
                'lines' => array_map(static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity_scaled' => $line['quantity_scaled'],
                    'unit_price_irr' => $line['unit_price_irr'],
                    'commission_rate_bps' => $line['commission_rate_bps'],
                ], $lines),
            ];
            $payloadHash = hash(
                'sha256',
                (string) json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $number = $this->repository->nextDocumentNumber('agent_sales_report', $fiscalYear);
            $result = $this->repository->createSalesReport([
                'fiscal_year' => $fiscalYear,
                'document_number' => $number,
                'account_id' => $accountId,
                'warehouse_id' => (int) $account['consignment_warehouse_id'],
                'reported_at' => $reportedAt,
                'external_reference' => $this->nullableText($data['external_reference'] ?? null, 191),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'gross_irr' => $grossTotal,
                'commission_irr' => $commissionTotal,
                'receivable_irr' => $receivableTotal,
                'notes' => $this->nullableText($data['notes'] ?? null, 1000),
                'correlation_id' => $correlationId,
                'actor_user_id' => $actor,
                'lines' => $lines,
            ]);
            if ($result['idempotent']) {
                return $this->requireSalesReport((int) $result['id']);
            }

            $cogsTotal = 0;
            foreach ($lines as $index => $line) {
                $consumption = $this->inventory->consume([
                    'product_id' => $line['product_id'],
                    'warehouse_id' => (int) $account['consignment_warehouse_id'],
                    'quantity' => $this->scaledToDecimal($line['quantity_scaled']),
                    'reference_type' => 'agent_sales_report',
                    'reference_id' => (string) $result['id'],
                    'correlation_id' => $correlationId,
                ], $actor);
                $this->repository->allocateSoldQuantity(
                    (int) $result['line_ids'][$index],
                    $accountId,
                    (int) $line['product_id'],
                    (int) $line['quantity_scaled']
                );
                $this->repository->attachSalesConsumption(
                    (int) $result['line_ids'][$index],
                    (int) $consumption['reservation_id'],
                    (int) $consumption['cogs_irr']
                );
                $lines[$index]['reservation_id'] = (int) $consumption['reservation_id'];
                $lines[$index]['cogs_irr'] = (int) $consumption['cogs_irr'];
                $cogsTotal += (int) $consumption['cogs_irr'];
            }

            $report = [
                'id' => (int) $result['id'],
                'document_number' => $number,
                'account_id' => $accountId,
                'account_name' => (string) $account['name'],
                'account_receivable_subsidiary_ledger_id' =>
                    $account['receivable_subsidiary_ledger_id'] ?? null,
                'account_floating_detail_id' => $account['floating_detail_id'] ?? null,
                'gross_irr' => $grossTotal,
                'commission_irr' => $commissionTotal,
                'receivable_irr' => $receivableTotal,
                'cogs_irr' => $cogsTotal,
                'correlation_id' => $correlationId,
                'lines' => $lines,
            ];
            $accounting = $this->accounting->postSalesReport($report, $actor);
            $dueDate = gmdate(
                'Y-m-d',
                strtotime($reportedAt . ' +' . (int) $account['settlement_terms_days'] . ' days')
            );
            $this->repository->finalizeSalesReport(
                (int) $result['id'],
                $accountId,
                $receivableTotal,
                $cogsTotal,
                $dueDate,
                $accounting
            );
            $this->audit->record(
                'b2b.agent_sales_report.posted',
                'agent_sales_report',
                (string) $result['id'],
                [
                    'account_id' => $accountId,
                    'gross_irr' => $grossTotal,
                    'commission_irr' => $commissionTotal,
                    'receivable_irr' => $receivableTotal,
                    'cogs_irr' => $cogsTotal,
                    'accounting_status' => $accounting === null ? 'pending_configuration' : 'posted',
                ],
                $correlationId
            );

            return $this->requireSalesReport((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function salesReport(int $reportId): array
    {
        return $this->requireSalesReport($this->positiveId($reportId, 'sales_report_id'));
    }

    /** @return list<array<string, mixed>> */
    public function salesReports(array $filters = []): array
    {
        return $this->repository->salesReports([
            'account_id' => $this->optionalPositiveId($filters['account_id'] ?? null),
            'status' => $this->nullableText($filters['status'] ?? null, 20),
        ]);
    }

    /** @return array<string, mixed> */
    private function requireSalesReport(int $reportId): array
    {
        $report = $this->repository->salesReport($reportId);
        if ($report === null) {
            throw new RuntimeException('Agent sales report not found.');
        }

        return $report;
    }
}
