<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\Accounting\Application\AccountingService;
use Rishe\B2B\Application\B2BAccountingGateway;

final class WpB2BAccountingGateway implements B2BAccountingGateway
{
    public function __construct(private readonly AccountingService $accounting)
    {
    }

    public function postSalesReport(array $report, int $actorUserId): ?array
    {
        $mapping = $this->mapping();
        if ($mapping === null) {
            return null;
        }
        $receivableLedger = (int) ($report['account_receivable_subsidiary_ledger_id']
            ?? $mapping['receivable_subsidiary_ledger_id']
            ?? 0);
        $salesLedger = (int) ($mapping['sales_subsidiary_ledger_id'] ?? 0);
        $commissionLedger = (int) ($mapping['commission_expense_subsidiary_ledger_id'] ?? 0);
        $cogsLedger = (int) ($mapping['cogs_subsidiary_ledger_id'] ?? 0);
        $inventoryLedger = (int) ($mapping['inventory_subsidiary_ledger_id'] ?? 0);
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        if (
            $receivableLedger < 1
            || $salesLedger < 1
            || $commissionLedger < 1
            || $cogsLedger < 1
            || $inventoryLedger < 1
            || $fiscalYear < 1
        ) {
            return null;
        }

        $detail = $report['account_floating_detail_id'] ?? null;
        $lines = [];
        if ((int) $report['receivable_irr'] > 0) {
            $lines[] = $this->line(
                $receivableLedger,
                $detail,
                (int) $report['receivable_irr'],
                0,
                'B2B receivable from agent sales report ' . $report['id']
            );
        }
        if ((int) $report['commission_irr'] > 0) {
            $lines[] = $this->line(
                $commissionLedger,
                $mapping['commission_expense_floating_detail_id'] ?? null,
                (int) $report['commission_irr'],
                0,
                'Agent commission on sales report ' . $report['id']
            );
        }
        $lines[] = $this->line(
            $salesLedger,
            $mapping['sales_floating_detail_id'] ?? null,
            0,
            (int) $report['gross_irr'],
            'Revenue from agent sales report ' . $report['id']
        );
        if ((int) $report['cogs_irr'] > 0) {
            $lines[] = $this->line(
                $cogsLedger,
                $mapping['cogs_floating_detail_id'] ?? null,
                (int) $report['cogs_irr'],
                0,
                'COGS from agent sales report ' . $report['id']
            );
            $lines[] = $this->line(
                $inventoryLedger,
                $mapping['inventory_floating_detail_id'] ?? null,
                0,
                (int) $report['cogs_irr'],
                'Consignment inventory issue for sales report ' . $report['id']
            );
        }
        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'Automatic posting for agent sales report ' . $report['id'],
            $lines,
            isset($report['correlation_id']) ? (string) $report['correlation_id'] : null
        );
        $number = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $number];
    }

    public function postSettlement(
        array $account,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $mapping = $this->mapping();
        $treasuryAccount = $treasuryTransaction['account'] ?? null;
        if ($mapping === null || !is_array($treasuryAccount)) {
            return null;
        }
        $receivableLedger = (int) ($account['receivable_subsidiary_ledger_id']
            ?? $mapping['receivable_subsidiary_ledger_id']
            ?? 0);
        $treasuryLedger = (int) ($treasuryAccount['subsidiary_ledger_id'] ?? 0);
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        if ($receivableLedger < 1 || $treasuryLedger < 1 || $fiscalYear < 1) {
            return null;
        }
        $lines = [
            $this->line(
                $treasuryLedger,
                $treasuryAccount['floating_detail_id'] ?? null,
                $amountIrr,
                0,
                'Treasury receipt from B2B account ' . $account['id']
            ),
            $this->line(
                $receivableLedger,
                $account['floating_detail_id'] ?? null,
                0,
                $amountIrr,
                'Settlement of B2B receivable ' . $account['id']
            ),
        ];
        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'Automatic B2B settlement for account ' . $account['id'],
            $lines
        );
        $number = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $number];
    }

    /** @return array<string, mixed>|null */
    private function mapping(): ?array
    {
        $mapping = get_option('rishe_b2b_accounting_mapping', []);

        return is_array($mapping) ? $mapping : null;
    }

    /** @return array<string, int|string|null> */
    private function line(
        int $ledgerId,
        mixed $floatingDetailId,
        int $debit,
        int $credit,
        string $description
    ): array {
        $detail = filter_var($floatingDetailId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'subsidiary_ledger_id' => $ledgerId,
            'floating_detail_id' => $detail === false ? null : (int) $detail,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];
    }
}
