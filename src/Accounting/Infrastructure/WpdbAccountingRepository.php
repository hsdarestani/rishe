<?php

declare(strict_types=1);

namespace Rishe\Accounting\Infrastructure;

use Rishe\Accounting\Application\AccountingRepository;
use Rishe\Accounting\Domain\Journal\JournalLine;
use RuntimeException;

final class WpdbAccountingRepository implements AccountingRepository
{
    public function createAccountGroup(array $data): int
    {
        global $wpdb;

        return $this->insertOrFail(
            $wpdb->prefix . 'rishe_account_groups',
            [
                'public_id' => wp_generate_uuid4(),
                'code' => $data['code'],
                'name' => $data['name'],
                'normal_balance' => $data['normal_balance'],
                'is_active' => 1,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function createGeneralLedger(array $data): int
    {
        global $wpdb;

        $this->assertActiveParent($wpdb->prefix . 'rishe_account_groups', (int) $data['account_group_id']);

        return $this->insertOrFail(
            $wpdb->prefix . 'rishe_general_ledgers',
            [
                'public_id' => wp_generate_uuid4(),
                'account_group_id' => $data['account_group_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'normal_balance' => $data['normal_balance'],
                'is_active' => 1,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function createSubsidiaryLedger(array $data): int
    {
        global $wpdb;

        $this->assertActiveParent($wpdb->prefix . 'rishe_general_ledgers', (int) $data['general_ledger_id']);

        return $this->insertOrFail(
            $wpdb->prefix . 'rishe_subsidiary_ledgers',
            [
                'public_id' => wp_generate_uuid4(),
                'general_ledger_id' => $data['general_ledger_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'normal_balance' => $data['normal_balance'],
                'requires_floating_detail' => $data['requires_floating_detail'] ? 1 : 0,
                'is_active' => 1,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
    }

    public function createFloatingDetail(array $data): int
    {
        global $wpdb;

        return $this->insertOrFail(
            $wpdb->prefix . 'rishe_floating_details',
            [
                'public_id' => wp_generate_uuid4(),
                'detail_type' => $data['detail_type'],
                'external_reference' => $data['external_reference'],
                'code' => $data['code'],
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'is_active' => 1,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function chart(): array
    {
        global $wpdb;

        $groups = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rishe_account_groups ORDER BY code",
            ARRAY_A
        );
        $generalLedgers = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rishe_general_ledgers ORDER BY code",
            ARRAY_A
        );
        $subsidiaryLedgers = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rishe_subsidiary_ledgers ORDER BY code",
            ARRAY_A
        );
        $floatingDetails = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rishe_floating_details ORDER BY detail_type, code",
            ARRAY_A
        );

        $subsidiaryByGeneral = [];
        foreach ($subsidiaryLedgers as $subsidiary) {
            $subsidiaryByGeneral[(int) $subsidiary['general_ledger_id']][] = $subsidiary;
        }

        $generalByGroup = [];
        foreach ($generalLedgers as $general) {
            $general['subsidiary_ledgers'] = $subsidiaryByGeneral[(int) $general['id']] ?? [];
            $generalByGroup[(int) $general['account_group_id']][] = $general;
        }

        foreach ($groups as &$group) {
            $group['general_ledgers'] = $generalByGroup[(int) $group['id']] ?? [];
        }
        unset($group);

        return [
            'account_groups' => $groups,
            'floating_details' => $floatingDetails,
        ];
    }

    public function subsidiaryRules(array $subsidiaryLedgerIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map('intval', $subsidiaryLedgerIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT id, requires_floating_detail, is_active
             FROM {$wpdb->prefix}rishe_subsidiary_ledgers
             WHERE id IN ({$placeholders})",
            ...$ids
        );
        $rows = $wpdb->get_results($query, ARRAY_A);
        $rules = [];

        foreach ($rows as $row) {
            $rules[(int) $row['id']] = [
                'requires_floating_detail' => (bool) $row['requires_floating_detail'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $rules;
    }

    public function insertVoucher(
        int $fiscalYear,
        string $voucherDate,
        string $status,
        string $description,
        int $totalDebit,
        int $totalCredit,
        array $lines,
        ?int $voucherNumber,
        ?int $reversalOfId,
        ?string $correlationId,
        ?int $postedBy,
        ?string $postedAt
    ): int {
        global $wpdb;

        $now = current_time('mysql', true);
        $voucherId = $this->insertOrFail(
            $wpdb->prefix . 'rishe_journal_vouchers',
            [
                'public_id' => wp_generate_uuid4(),
                'fiscal_year' => $fiscalYear,
                'voucher_number' => $voucherNumber,
                'voucher_date' => $voucherDate,
                'status' => $status,
                'description' => $description,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'reversal_of_id' => $reversalOfId,
                'correlation_id' => $correlationId,
                'created_by' => get_current_user_id() ?: null,
                'posted_by' => $postedBy,
                'posted_at' => $postedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        foreach ($lines as $index => $line) {
            $inserted = $wpdb->insert(
                $wpdb->prefix . 'rishe_journal_entries',
                [
                    'voucher_id' => $voucherId,
                    'line_number' => $index + 1,
                    'subsidiary_ledger_id' => $line->subsidiaryLedgerId(),
                    'floating_detail_id' => $line->floatingDetailId(),
                    'debit' => $line->debit(),
                    'credit' => $line->credit(),
                    'description' => $line->description(),
                    'created_at' => $now,
                ],
                ['%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
            );

            if ($inserted === false) {
                throw new RuntimeException('Unable to insert a journal entry. ' . $wpdb->last_error);
            }
        }

        return $voucherId;
    }

    public function voucherForUpdate(int $voucherId): ?array
    {
        global $wpdb;

        $voucher = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rishe_journal_vouchers WHERE id = %d FOR UPDATE",
                $voucherId
            ),
            ARRAY_A
        );

        if ($voucher === null) {
            return null;
        }

        $voucher['entries'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, s.requires_floating_detail, s.is_active AS subsidiary_is_active
                 FROM {$wpdb->prefix}rishe_journal_entries e
                 INNER JOIN {$wpdb->prefix}rishe_subsidiary_ledgers s ON s.id = e.subsidiary_ledger_id
                 WHERE e.voucher_id = %d
                 ORDER BY e.line_number",
                $voucherId
            ),
            ARRAY_A
        );

        return $voucher;
    }

    public function nextVoucherNumber(int $fiscalYear): int
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}rishe_voucher_sequences (fiscal_year, last_number, updated_at)
             VALUES (%d, LAST_INSERT_ID(1), %s)
             ON DUPLICATE KEY UPDATE
                last_number = LAST_INSERT_ID(last_number + 1),
                updated_at = VALUES(updated_at)",
            $fiscalYear,
            current_time('mysql', true)
        );

        if ($wpdb->query($query) === false) {
            throw new RuntimeException('Unable to allocate the next voucher number.');
        }

        $number = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($number < 1) {
            throw new RuntimeException('The allocated voucher number is invalid.');
        }

        return $number;
    }

    public function markPosted(int $voucherId, int $voucherNumber, int $actorUserId, string $postedAt): void
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}rishe_journal_vouchers
                 SET status = 'posted', voucher_number = %d, posted_by = %d, posted_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('draft', 'temporary')",
                $voucherNumber,
                $actorUserId,
                $postedAt,
                $postedAt,
                $voucherId
            )
        );

        if ($updated !== 1) {
            throw new RuntimeException('Voucher status changed before it could be posted.');
        }
    }

    public function markReversed(int $voucherId): void
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}rishe_journal_vouchers
                 SET status = 'reversed', updated_at = %s
                 WHERE id = %d AND status = 'posted'",
                current_time('mysql', true),
                $voucherId
            )
        );

        if ($updated !== 1) {
            throw new RuntimeException('Voucher could not be marked as reversed.');
        }
    }

    public function findReversalId(int $voucherId): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rishe_journal_vouchers WHERE reversal_of_id = %d LIMIT 1",
                $voucherId
            )
        );

        return $id === null ? null : (int) $id;
    }

    public function trialBalance(string $fromDate, string $toDate): array
    {
        global $wpdb;

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
             FROM {$wpdb->prefix}rishe_journal_entries e
             INNER JOIN {$wpdb->prefix}rishe_journal_vouchers v ON v.id = e.voucher_id
             INNER JOIN {$wpdb->prefix}rishe_subsidiary_ledgers s ON s.id = e.subsidiary_ledger_id
             INNER JOIN {$wpdb->prefix}rishe_general_ledgers gl ON gl.id = s.general_ledger_id
             INNER JOIN {$wpdb->prefix}rishe_account_groups g ON g.id = gl.account_group_id
             LEFT JOIN {$wpdb->prefix}rishe_floating_details d ON d.id = e.floating_detail_id
             WHERE v.status IN ('posted', 'reversed') AND v.voucher_date BETWEEN %s AND %s
             GROUP BY g.code, g.name, gl.code, gl.name, s.id, s.code, s.name, s.normal_balance,
                      d.id, d.code, d.name
             ORDER BY g.code, gl.code, s.code, d.code",
            $fromDate,
            $toDate
        );

        return $wpdb->get_results($query, ARRAY_A);
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insertOrFail(string $table, array $data, array $formats): int
    {
        global $wpdb;

        if ($wpdb->insert($table, $data, $formats) === false) {
            throw new RuntimeException('Unable to persist accounting data. ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    private function assertActiveParent(string $table, int $id): void
    {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE id = %d AND is_active = 1", $id)
        );

        if ($exists === null) {
            throw new RuntimeException('The parent account does not exist or is inactive.');
        }
    }
}
