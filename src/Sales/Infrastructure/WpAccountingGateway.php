<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Sales\Application\AccountingGateway;

final class WpAccountingGateway implements AccountingGateway
{
    public function __construct(private readonly AccountingService $accounting)
    {
    }

    public function postPaidOrder(array $order, int $actorUserId): ?array
    {
        $mapping = get_option('rishe_sales_accounting_mapping', []);
        if (!is_array($mapping)) {
            return null;
        }

        $required = [
            'fiscal_year',
            'settlement_subsidiary_ledger_id',
            'sales_subsidiary_ledger_id',
            'cogs_subsidiary_ledger_id',
            'inventory_subsidiary_ledger_id',
        ];
        foreach ($required as $field) {
            if ((int) ($mapping[$field] ?? 0) < 1) {
                return null;
            }
        }

        $orderId = (int) $order['id'];
        $total = (int) $order['total_irr'];
        $cogs = (int) ($order['cogs_irr'] ?? 0);
        $lines = [
            $this->line(
                (int) $mapping['settlement_subsidiary_ledger_id'],
                $mapping['settlement_floating_detail_id'] ?? null,
                $total,
                0,
                'Settlement for sales order ' . $orderId
            ),
            $this->line(
                (int) $mapping['sales_subsidiary_ledger_id'],
                $mapping['sales_floating_detail_id'] ?? null,
                0,
                $total,
                'Revenue for sales order ' . $orderId
            ),
        ];
        if ($cogs > 0) {
            $lines[] = $this->line(
                (int) $mapping['cogs_subsidiary_ledger_id'],
                $mapping['cogs_floating_detail_id'] ?? null,
                $cogs,
                0,
                'COGS for sales order ' . $orderId
            );
            $lines[] = $this->line(
                (int) $mapping['inventory_subsidiary_ledger_id'],
                $mapping['inventory_floating_detail_id'] ?? null,
                0,
                $cogs,
                'Inventory issue for sales order ' . $orderId
            );
        }

        $voucherId = $this->accounting->createDraftVoucher(
            (int) $mapping['fiscal_year'],
            gmdate('Y-m-d'),
            'Automatic posting for sales order ' . $orderId,
            $lines,
            isset($order['correlation_id']) ? (string) $order['correlation_id'] : null
        );
        $voucherNumber = $this->accounting->postVoucher($voucherId, $actorUserId);

        return ['voucher_id' => $voucherId, 'voucher_number' => $voucherNumber];
    }

    /** @return array<string, int|string|null> */
    private function line(
        int $ledgerId,
        mixed $floatingDetailId,
        int $debit,
        int $credit,
        string $description
    ): array {
        $detailId = filter_var($floatingDetailId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'subsidiary_ledger_id' => $ledgerId,
            'floating_detail_id' => $detailId === false ? null : (int) $detailId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];
    }
}
