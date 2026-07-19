<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Procurement\Application\ProcurementAccountingGateway;

final class WpProcurementAccountingGateway implements ProcurementAccountingGateway
{
    public function __construct(private readonly AccountingService $accounting)
    {
    }

    public function postReceipt(array $receipt, int $actorUserId): ?array
    {
        $mapping = $this->mapping();
        if ($mapping === null) {
            return null;
        }
        $payableLedger = (int) ($receipt['supplier_payable_subsidiary_ledger_id']
            ?? $mapping['payable_subsidiary_ledger_id']
            ?? 0);
        $inventoryLedger = (int) ($mapping['inventory_subsidiary_ledger_id'] ?? 0);
        $taxLedger = (int) ($mapping['input_tax_subsidiary_ledger_id'] ?? 0);
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        $tax = (int) ($receipt['tax_irr'] ?? 0);
        if ($payableLedger < 1 || $inventoryLedger < 1 || $fiscalYear < 1 || ($tax > 0 && $taxLedger < 1)) {
            return null;
        }

        $capitalized = (int) $receipt['merchandise_value_irr'] + (int) $receipt['landed_cost_irr'];
        $liability = (int) $receipt['liability_irr'];
        $detail = $receipt['supplier_floating_detail_id'] ?? null;
        $lines = [
            $this->line(
                $inventoryLedger,
                $mapping['inventory_floating_detail_id'] ?? null,
                $capitalized,
                0,
                'Inventory received on purchase receipt ' . $receipt['id']
            ),
        ];
        if ($tax > 0) {
            $lines[] = $this->line(
                $taxLedger,
                $mapping['input_tax_floating_detail_id'] ?? null,
                $tax,
                0,
                'Recoverable input tax on purchase receipt ' . $receipt['id']
            );
        }
        $lines[] = $this->line(
            $payableLedger,
            $detail,
            0,
            $liability,
            'Supplier liability from purchase receipt ' . $receipt['id']
        );

        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'Automatic posting for purchase receipt ' . $receipt['id'],
            $lines,
            isset($receipt['correlation_id']) ? (string) $receipt['correlation_id'] : null
        );
        $number = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $number];
    }

    public function postPayment(
        array $purchaseOrder,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $mapping = $this->mapping();
        $account = $treasuryTransaction['account'] ?? null;
        if ($mapping === null || !is_array($account)) {
            return null;
        }
        $payableLedger = (int) ($purchaseOrder['supplier_payable_subsidiary_ledger_id']
            ?? $mapping['payable_subsidiary_ledger_id']
            ?? 0);
        $treasuryLedger = (int) ($account['subsidiary_ledger_id'] ?? 0);
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        if ($payableLedger < 1 || $treasuryLedger < 1 || $fiscalYear < 1) {
            return null;
        }

        $lines = [
            $this->line(
                $payableLedger,
                $purchaseOrder['supplier_floating_detail_id'] ?? null,
                $amountIrr,
                0,
                'Supplier payment for purchase order ' . $purchaseOrder['id']
            ),
            $this->line(
                $treasuryLedger,
                $account['floating_detail_id'] ?? null,
                0,
                $amountIrr,
                'Treasury payment for purchase order ' . $purchaseOrder['id']
            ),
        ];
        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'Automatic supplier payment for purchase order ' . $purchaseOrder['id'],
            $lines,
            isset($purchaseOrder['correlation_id']) ? (string) $purchaseOrder['correlation_id'] : null
        );
        $number = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $number];
    }

    /** @return array<string, mixed>|null */
    private function mapping(): ?array
    {
        $mapping = get_option('rishe_procurement_accounting_mapping', []);

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
