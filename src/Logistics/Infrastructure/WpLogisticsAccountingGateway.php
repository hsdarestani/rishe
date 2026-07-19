<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Logistics\Application\LogisticsAccountingGateway;

final class WpLogisticsAccountingGateway implements LogisticsAccountingGateway
{
    public function __construct(private readonly AccountingService $accounting)
    {
    }

    public function postCarrierSettlement(
        array $shipment,
        array $treasuryTransaction,
        int $amountIrr,
        int $actorUserId
    ): ?array {
        $mapping = get_option('rishe_logistics_accounting_mapping', []);
        $mapping = is_array($mapping) ? $mapping : [];
        $account = $treasuryTransaction['account'] ?? null;
        if (!is_array($account)) {
            return null;
        }
        $expenseLedger = (int) ($shipment['carrier_shipping_expense_subsidiary_ledger_id']
            ?? $mapping['shipping_expense_subsidiary_ledger_id']
            ?? 0);
        $treasuryLedger = (int) ($account['subsidiary_ledger_id'] ?? 0);
        $fiscalYear = (int) ($mapping['fiscal_year'] ?? 0);
        if ($expenseLedger < 1 || $treasuryLedger < 1 || $fiscalYear < 1) {
            return null;
        }
        $expenseDetail = $this->positiveOrNull($mapping['shipping_expense_floating_detail_id'] ?? null);
        $bankDetail = $this->positiveOrNull($account['floating_detail_id'] ?? null);
        $voucherId = $this->accounting->createDraftVoucher(
            $fiscalYear,
            gmdate('Y-m-d'),
            'Automatic carrier settlement for shipment ' . $shipment['id'],
            [
                [
                    'subsidiary_ledger_id' => $expenseLedger,
                    'floating_detail_id' => $expenseDetail,
                    'debit' => $amountIrr,
                    'credit' => 0,
                    'description' => 'Carrier cost for shipment ' . $shipment['id'],
                ],
                [
                    'subsidiary_ledger_id' => $treasuryLedger,
                    'floating_detail_id' => $bankDetail,
                    'debit' => 0,
                    'credit' => $amountIrr,
                    'description' => 'Treasury settlement for shipment ' . $shipment['id'],
                ],
            ],
            isset($shipment['correlation_id']) ? (string) $shipment['correlation_id'] : null
        );
        $number = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $number];
    }

    private function positiveOrNull(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }
}
