<?php

declare(strict_types=1);

namespace Rishe\Accounting\Application;

use RuntimeException;

final class AccountingReviewService
{
    public function __construct(private readonly AccountingService $accounting)
    {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        global $wpdb;

        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        $reviews = $wpdb->prefix . 'rishe_voucher_reviews';
        $rows = $wpdb->get_results(
            "SELECT COALESCE(r.review_status, 'pending') AS review_status, COUNT(*) AS total
             FROM {$vouchers} v LEFT JOIN {$reviews} r ON r.voucher_id=v.id
             WHERE v.status <> 'reversed'
             GROUP BY COALESCE(r.review_status, 'pending')",
            ARRAY_A
        );
        $result = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $status = (string) $row['review_status'];
            if (isset($result[$status])) {
                $result[$status] = (int) $row['total'];
            }
            $result['all'] += (int) $row['total'];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function queue(string $status = 'pending', string $source = ''): array
    {
        global $wpdb;

        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        $reviews = $wpdb->prefix . 'rishe_voucher_reviews';
        $users = $wpdb->users;
        $clauses = ["v.status <> 'reversed'"];
        $args = [];
        if ($status !== '' && $status !== 'all') {
            $clauses[] = "COALESCE(r.review_status, 'pending') = %s";
            $args[] = $status;
        }
        $sql = "SELECT v.*, COALESCE(r.review_status, 'pending') AS review_status,
                       r.source_type, r.source_id, r.title, r.review_note, r.reviewed_by,
                       r.reviewed_at, r.reversal_voucher_id,
                       creator.display_name AS creator_name, reviewer.display_name AS reviewer_name
                FROM {$vouchers} v
                LEFT JOIN {$reviews} r ON r.voucher_id=v.id
                LEFT JOIN {$users} creator ON creator.ID=v.created_by
                LEFT JOIN {$users} reviewer ON reviewer.ID=r.reviewed_by
                WHERE " . implode(' AND ', $clauses) . '
                ORDER BY CASE COALESCE(r.review_status, \'pending\') WHEN \'pending\' THEN 0 ELSE 1 END,
                         v.voucher_date DESC, v.id DESC LIMIT 300';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);
        $formatted = array_map(fn (array $row): array => $this->formatRow($row), is_array($rows) ? $rows : []);
        if ($source !== '') {
            $formatted = array_values(array_filter(
                $formatted,
                static fn (array $row): bool => (string) ($row['source_type'] ?? '') === $source
            ));
        }

        return $formatted;
    }

    /** @return array<string, mixed> */
    public function voucher(int $voucherId): array
    {
        global $wpdb;

        $vouchers = $wpdb->prefix . 'rishe_journal_vouchers';
        $reviews = $wpdb->prefix . 'rishe_voucher_reviews';
        $entries = $wpdb->prefix . 'rishe_journal_entries';
        $subsidiary = $wpdb->prefix . 'rishe_subsidiary_ledgers';
        $general = $wpdb->prefix . 'rishe_general_ledgers';
        $details = $wpdb->prefix . 'rishe_floating_details';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, COALESCE(r.review_status, 'pending') AS review_status,
                    r.source_type, r.source_id, r.title, r.review_note, r.reviewed_by,
                    r.reviewed_at, r.reversal_voucher_id
             FROM {$vouchers} v LEFT JOIN {$reviews} r ON r.voucher_id=v.id WHERE v.id=%d",
            $voucherId
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('سند حسابداری پیدا نشد.');
        }
        $row = $this->formatRow($row);
        $lines = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, s.code AS subsidiary_code, s.name AS subsidiary_name,
                    g.code AS general_code, g.name AS general_name,
                    d.code AS floating_code, d.name AS floating_name
             FROM {$entries} e
             INNER JOIN {$subsidiary} s ON s.id=e.subsidiary_ledger_id
             INNER JOIN {$general} g ON g.id=s.general_ledger_id
             LEFT JOIN {$details} d ON d.id=e.floating_detail_id
             WHERE e.voucher_id=%d ORDER BY e.line_number",
            $voucherId
        ), ARRAY_A);
        $row['entries'] = array_map(static function (array $line): array {
            foreach (['id', 'voucher_id', 'line_number', 'subsidiary_ledger_id', 'floating_detail_id', 'debit', 'credit'] as $field) {
                $line[$field] = $line[$field] === null ? null : (int) $line[$field];
            }

            return $line;
        }, is_array($lines) ? $lines : []);

        return $row;
    }

    /** @return array<string, mixed> */
    public function approve(int $voucherId, int $actorUserId, string $note = ''): array
    {
        $voucher = $this->voucher($voucherId);
        if ((string) $voucher['review_status'] === 'rejected') {
            throw new RuntimeException('سند ردشده قابل تأیید نیست.');
        }
        $number = (int) ($voucher['voucher_number'] ?? 0);
        if (in_array((string) $voucher['status'], ['draft', 'temporary'], true)) {
            $number = $this->accounting->postVoucher($voucherId, $actorUserId);
        } elseif ((string) $voucher['status'] !== 'posted') {
            throw new RuntimeException('وضعیت سند برای تأیید معتبر نیست.');
        }
        $this->saveReview($voucherId, 'approved', $actorUserId, $note, null);

        return $this->voucher($voucherId) + ['voucher_number' => $number];
    }

    /** @return array<string, mixed> */
    public function reject(int $voucherId, int $actorUserId, string $note): array
    {
        global $wpdb;

        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('برای رد سند، علت را وارد کنید.');
        }
        $voucher = $this->voucher($voucherId);
        if ((string) $voucher['review_status'] === 'approved') {
            throw new RuntimeException('سند تأییدشده را ابتدا باید با سند اصلاحی برگردانید.');
        }
        $reversalId = null;
        if ((string) $voucher['status'] === 'posted') {
            $reversalId = $this->accounting->reverseVoucher(
                $voucherId,
                (int) $voucher['fiscal_year'],
                gmdate('Y-m-d'),
                'رد سند #' . $voucherId . ': ' . $note,
                $actorUserId
            );
        } elseif (in_array((string) $voucher['status'], ['draft', 'temporary'], true)) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'rishe_journal_vouchers',
                ['status' => 'rejected', 'updated_at' => current_time('mysql', true)],
                ['id' => $voucherId],
                ['%s', '%s'],
                ['%d']
            );
            if ($updated !== 1) {
                throw new RuntimeException('رد سند انجام نشد.');
            }
        } else {
            throw new RuntimeException('وضعیت سند برای رد معتبر نیست.');
        }
        $this->saveReview($voucherId, 'rejected', $actorUserId, $note, $reversalId);

        return $this->voucher($voucherId);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createGeneratedDocument(array $payload, int $actorUserId): array
    {
        $type = sanitize_key((string) ($payload['template'] ?? 'manual'));
        $templates = $this->templates();
        if (!isset($templates[$type])) {
            throw new RuntimeException('نوع سند انتخاب‌شده معتبر نیست.');
        }
        $amount = filter_var($payload['amount_irr'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $debitLedger = filter_var($payload['debit_ledger_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $creditLedger = filter_var($payload['credit_ledger_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false || $debitLedger === false || $creditLedger === false) {
            throw new RuntimeException('مبلغ و حساب‌های بدهکار و بستانکار الزامی‌اند.');
        }
        $date = sanitize_text_field((string) ($payload['voucher_date'] ?? gmdate('Y-m-d')));
        $year = (int) ($payload['fiscal_year'] ?? 1405);
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            $description = $templates[$type]['title'];
        }
        $debitDetail = $this->optionalId($payload['debit_floating_detail_id'] ?? null);
        $creditDetail = $this->optionalId($payload['credit_floating_detail_id'] ?? null);
        $voucherId = $this->accounting->createDraftVoucher(
            $year,
            $date,
            $description,
            [
                [
                    'subsidiary_ledger_id' => (int) $debitLedger,
                    'floating_detail_id' => $debitDetail,
                    'debit' => (int) $amount,
                    'credit' => 0,
                    'description' => $description,
                ],
                [
                    'subsidiary_ledger_id' => (int) $creditLedger,
                    'floating_detail_id' => $creditDetail,
                    'debit' => 0,
                    'credit' => (int) $amount,
                    'description' => $description,
                ],
            ],
            'generated-' . $type . '-' . wp_generate_uuid4()
        );
        $this->ensureReview(
            $voucherId,
            $type,
            isset($payload['source_id']) ? sanitize_text_field((string) $payload['source_id']) : null,
            $templates[$type]['title'],
            ['created_by' => $actorUserId]
        );

        return $this->voucher($voucherId);
    }

    /** @return array<string, array{title:string,description:string}> */
    public function templates(): array
    {
        return [
            'payroll' => ['title' => 'حقوق و دستمزد', 'description' => 'ثبت هزینه حقوق و بدهی یا پرداخت کارکنان'],
            'rent' => ['title' => 'اجاره', 'description' => 'ثبت اجاره دفتر، انبار، غرفه یا دکان'],
            'contractor' => ['title' => 'هزینه پیمانکار', 'description' => 'ثبت خدمات بسته‌بندی، طراحی، حمل یا سایر پیمانکاران'],
            'operating_expense' => ['title' => 'هزینه جاری', 'description' => 'قبوض، اینترنت، ملزومات و سایر هزینه‌های جاری'],
            'purchase_adjustment' => ['title' => 'اصلاح خرید', 'description' => 'اصلاح مالی خرید یا هزینه جانبی تأمین'],
            'sales_adjustment' => ['title' => 'اصلاح فروش', 'description' => 'اصلاح درآمد، تخفیف یا تسویه فروش'],
            'manual' => ['title' => 'سند دستی', 'description' => 'سند دوطرفه ساده برای موارد متفرقه'],
        ];
    }

    /** @param array<string, mixed> $metadata */
    public function ensureReview(
        int $voucherId,
        string $sourceType = 'accounting',
        ?string $sourceId = null,
        ?string $title = null,
        array $metadata = []
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_voucher_reviews';
        $now = current_time('mysql', true);
        $json = wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (public_id, voucher_id, review_status, source_type, source_id, title, metadata_json, created_at, updated_at)
             VALUES (%s, %d, 'pending', %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE source_type=VALUES(source_type), source_id=VALUES(source_id),
                 title=COALESCE(VALUES(title), title), metadata_json=VALUES(metadata_json), updated_at=VALUES(updated_at)",
            wp_generate_uuid4(),
            $voucherId,
            $sourceType,
            $sourceId,
            $title,
            $json === false ? '{}' : $json,
            $now,
            $now
        );
        if ($wpdb->query($sql) === false) {
            throw new RuntimeException('ثبت سند در کارتابل حسابداری انجام نشد.');
        }
    }

    private function saveReview(
        int $voucherId,
        string $status,
        int $actorUserId,
        string $note,
        ?int $reversalId
    ): void {
        global $wpdb;

        $this->ensureReview($voucherId);
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_voucher_reviews',
            [
                'review_status' => $status,
                'reviewed_by' => $actorUserId,
                'reviewed_at' => current_time('mysql', true),
                'review_note' => $note,
                'reversal_voucher_id' => $reversalId,
                'updated_at' => current_time('mysql', true),
            ],
            ['voucher_id' => $voucherId],
            ['%s', '%d', '%s', '%s', '%d', '%s'],
            ['%d']
        );
        if ($updated === false) {
            throw new RuntimeException('ثبت نتیجه بررسی سند ناموفق بود.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRow(array $row): array
    {
        foreach ([
            'id', 'fiscal_year', 'voucher_number', 'total_debit', 'total_credit', 'created_by', 'posted_by',
            'reviewed_by', 'reversal_voucher_id',
        ] as $field) {
            $row[$field] = ($row[$field] ?? null) === null ? null : (int) $row[$field];
        }
        $source = (string) ($row['source_type'] ?? '');
        if ($source === '') {
            [$source, $label] = $this->classify((string) ($row['description'] ?? ''), (string) ($row['correlation_id'] ?? ''));
            $row['source_type'] = $source;
            $row['source_label'] = $label;
        } else {
            $row['source_label'] = $this->sourceLabel($source);
        }
        $row['title'] = trim((string) ($row['title'] ?? '')) ?: trim((string) ($row['description'] ?? ''));
        $row['amount_irr'] = max((int) ($row['total_debit'] ?? 0), (int) ($row['total_credit'] ?? 0));

        return $row;
    }

    /** @return array{string,string} */
    private function classify(string $description, string $correlation): array
    {
        $text = strtolower($description . ' ' . $correlation);
        $map = [
            'sales' => ['sales', 'فروش', 'woocommerce', 'order'],
            'purchase' => ['purchase', 'supplier', 'خرید', 'تأمین'],
            'b2b' => ['b2b', 'agent sales', 'consignment'],
            'logistics' => ['logistics', 'shipment', 'carrier', 'حمل'],
            'treasury' => ['treasury', 'bank', 'payment', 'بانک', 'خزانه'],
            'payroll' => ['payroll', 'salary', 'حقوق'],
            'rent' => ['rent', 'اجاره'],
            'contractor' => ['contractor', 'پیمانکار'],
        ];
        foreach ($map as $source => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return [$source, $this->sourceLabel($source)];
                }
            }
        }

        return ['accounting', 'حسابداری'];
    }

    private function sourceLabel(string $source): string
    {
        return [
            'sales' => 'فروش',
            'purchase' => 'خرید و تأمین',
            'b2b' => 'فروش B2B',
            'logistics' => 'لجستیک',
            'treasury' => 'خزانه و بانک',
            'payroll' => 'منابع انسانی و حقوق',
            'rent' => 'اجاره',
            'contractor' => 'پیمانکار',
            'operating_expense' => 'هزینه جاری',
            'purchase_adjustment' => 'اصلاح خرید',
            'sales_adjustment' => 'اصلاح فروش',
            'manual' => 'سند دستی',
            'accounting' => 'حسابداری',
        ][$source] ?? $source;
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }
}
