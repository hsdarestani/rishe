<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TemporaryFinancialRestApi
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('rishe/v1', '/business/finance/summary', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'summary'],
            'permission_callback' => static fn (): bool => current_user_can('rishe_view_reports')
                || current_user_can('manage_rishe'),
        ], true);
    }

    public function summary(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $from = sanitize_text_field((string) ($request->get_param('from') ?: gmdate('Y-01-01')));
            $to = sanitize_text_field((string) ($request->get_param('to') ?: gmdate('Y-m-d')));
            $rows = $this->trialBalance($from, $to);
            $totals = [
                'assets' => 0,
                'liabilities' => 0,
                'equity' => 0,
                'revenue' => 0,
                'expenses' => 0,
            ];

            foreach ($rows as $row) {
                $name = (string) (($row['group_name'] ?? '') . ' ' . ($row['general_name'] ?? ''));
                $code = (string) ($row['group_code'] ?? '');
                $debit = (int) ($row['debit_balance'] ?? 0);
                $credit = (int) ($row['credit_balance'] ?? 0);
                $balance = max($debit, $credit);

                if ($this->containsAny($name, ['دارایی', 'موجودی', 'بانک', 'صندوق', 'دریافتنی'])
                    || str_starts_with($code, '1')) {
                    $totals['assets'] += $balance;
                } elseif ($this->containsAny($name, ['بدهی', 'پرداختنی', 'تعهد'])
                    || str_starts_with($code, '2')) {
                    $totals['liabilities'] += $balance;
                } elseif ($this->containsAny($name, ['سرمایه', 'حقوق مالکانه', 'اندوخته'])
                    || str_starts_with($code, '3')) {
                    $totals['equity'] += $balance;
                } elseif ($this->containsAny($name, ['درآمد', 'فروش']) || str_starts_with($code, '4')) {
                    $totals['revenue'] += $credit > 0 ? $credit : $balance;
                } elseif ($this->containsAny($name, ['هزینه', 'بهای تمام شده', 'بهای تمام‌شده'])
                    || str_starts_with($code, '5')
                    || str_starts_with($code, '6')) {
                    $totals['expenses'] += $debit > 0 ? $debit : $balance;
                }
            }

            $profit = $totals['revenue'] - $totals['expenses'];
            return new WP_REST_Response([
                'from' => $from,
                'to' => $to,
                'trial_balance' => $rows,
                'income_statement' => [
                    'revenue_irr' => $totals['revenue'],
                    'expenses_irr' => $totals['expenses'],
                    'profit_irr' => $profit,
                ],
                'balance_sheet' => [
                    'assets_irr' => $totals['assets'],
                    'liabilities_irr' => $totals['liabilities'],
                    'equity_irr' => $totals['equity'],
                    'difference_irr' => $totals['assets'] - $totals['liabilities'] - $totals['equity'],
                ],
                'cash_flow' => $this->cashFlow($from, $to),
                'equity_statement' => [
                    'closing_equity_irr' => $totals['equity'] + $profit,
                    'period_profit_irr' => $profit,
                ],
                'temporary' => true,
                'included_voucher_statuses' => ['draft', 'temporary', 'posted', 'reversed'],
            ]);
        } catch (Throwable $exception) {
            return new WP_REST_Response([
                'error' => 'ساخت صورت‌های مالی موقت با خطا روبه‌رو شد.',
                'technical' => current_user_can('manage_options') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    /** @return list<array<string, mixed>> */
    private function trialBalance(string $from, string $to): array
    {
        global $wpdb;

        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        if (!$this->tableExists($vouchers)) {
            return [];
        }
        $entries = $wpdb->prefix . 'rishe_journal_entries';
        $subsidiaries = $wpdb->prefix . 'rishe_subsidiary_ledgers';
        $generals = $wpdb->prefix . 'rishe_general_ledgers';
        $groups = $wpdb->prefix . 'rishe_account_groups';
        $details = $wpdb->prefix . 'rishe_floating_details';

        $query = $wpdb->prepare(
            "SELECT
                g.code AS group_code,
                g.name AS group_name,
                gl.code AS general_code,
                gl.name AS general_name,
                s.id AS subsidiary_ledger_id,
                s.code AS subsidiary_code,
                s.name AS subsidiary_name,
                s.normal_balance,
                d.id AS floating_detail_id,
                d.code AS floating_detail_code,
                d.name AS floating_detail_name,
                SUM(e.debit) AS total_debit,
                SUM(e.credit) AS total_credit,
                GREATEST(SUM(e.debit) - SUM(e.credit), 0) AS debit_balance,
                GREATEST(SUM(e.credit) - SUM(e.debit), 0) AS credit_balance
             FROM {$entries} e
             INNER JOIN {$vouchers} v ON v.id = e.voucher_id
             INNER JOIN {$subsidiaries} s ON s.id = e.subsidiary_ledger_id
             INNER JOIN {$generals} gl ON gl.id = s.general_ledger_id
             INNER JOIN {$groups} g ON g.id = gl.account_group_id
             LEFT JOIN {$details} d ON d.id = e.floating_detail_id
             WHERE v.status IN ('draft', 'temporary', 'posted', 'reversed')
               AND v.voucher_date BETWEEN %s AND %s
             GROUP BY g.code, g.name, gl.code, gl.name, s.id, s.code, s.name, s.normal_balance,
                      d.id, d.code, d.name
             ORDER BY g.code, gl.code, s.code, d.code",
            $from,
            $to
        );
        $rows = $wpdb->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @return array{inflow_irr:int,outflow_irr:int,net_irr:int} */
    private function cashFlow(string $from, string $to): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_treasury_transactions';
        if (!$this->tableExists($table)) {
            return ['inflow_irr' => 0, 'outflow_irr' => 0, 'net_irr' => 0];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT direction, SUM(amount_irr) amount
             FROM {$table}
             WHERE transaction_at BETWEEN %s AND %s
             GROUP BY direction",
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ), ARRAY_A);
        $inflow = 0;
        $outflow = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $amount = (int) ($row['amount'] ?? 0);
            if (in_array((string) ($row['direction'] ?? ''), ['in', 'credit', 'incoming'], true)) {
                $inflow += $amount;
            } else {
                $outflow += $amount;
            }
        }

        return ['inflow_irr' => $inflow, 'outflow_irr' => $outflow, 'net_irr' => $inflow - $outflow];
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
