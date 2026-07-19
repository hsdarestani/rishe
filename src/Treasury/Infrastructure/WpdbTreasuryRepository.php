<?php

declare(strict_types=1);

namespace Rishe\Treasury\Infrastructure;

use Rishe\Treasury\Application\TreasuryRepository;
use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use RuntimeException;

final class WpdbTreasuryRepository implements TreasuryRepository
{
    public function createAccount(array $data): int
    {
        return $this->insert('rishe_treasury_accounts', [
            'public_id' => wp_generate_uuid4(),
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_name' => $data['bank_name'],
            'iban' => $data['iban'],
            'account_number' => $data['account_number'],
            'card_number' => $data['card_number'],
            'currency' => $data['currency'],
            'subsidiary_ledger_id' => $data['subsidiary_ledger_id'],
            'floating_detail_id' => $data['floating_detail_id'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s',
        ], 'treasury account');
    }

    public function account(int $accountId): ?array
    {
        return $this->row('rishe_treasury_accounts', $accountId);
    }

    public function createProvider(array $data): int
    {
        return $this->insert('rishe_treasury_providers', [
            'public_id' => wp_generate_uuid4(),
            'code' => $data['code'],
            'name' => $data['name'],
            'adapter' => $data['adapter'],
            'treasury_account_id' => $data['treasury_account_id'],
            'config_json' => wp_json_encode($data['config']),
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s'], 'treasury provider');
    }

    public function providerByCode(string $code): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_treasury_providers';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code = %s", $code), ARRAY_A);

        return is_array($row) ? $this->formatProvider($row) : null;
    }

    public function providerById(int $providerId): ?array
    {
        $row = $this->row('rishe_treasury_providers', $providerId);

        return $row === null ? null : $this->formatProvider($row);
    }

    public function createPaymentLink(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_payment_links';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, payload_hash FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
            $data['idempotency_key']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['payload_hash'], (string) $data['payload_hash'])) {
                throw new TreasuryDomainException('Payment-link idempotency key was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }

        $id = $this->insert('rishe_payment_links', [
            'public_id' => wp_generate_uuid4(),
            'provider_id' => $data['provider_id'],
            'treasury_account_id' => $data['treasury_account_id'],
            'sales_order_id' => $data['sales_order_id'],
            'customer_id' => $data['customer_id'],
            'amount_irr' => $data['amount_irr'],
            'status' => 'creating',
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $data['payload_hash'],
            'provider_link_id' => null,
            'payment_url' => null,
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'description' => $data['description'],
            'expires_at' => $data['expires_at'],
            'paid_transaction_id' => null,
            'correlation_id' => $data['correlation_id'],
            'created_by' => $data['actor_user_id'],
            'activated_at' => null,
            'paid_at' => null,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], [
            '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%d', '%s', '%d', '%s', '%s', '%s', '%s',
        ], 'payment link');

        return ['id' => $id, 'idempotent' => false];
    }

    public function paymentLink(int $paymentLinkId): ?array
    {
        global $wpdb;

        $links = $wpdb->prefix . 'rishe_payment_links';
        $providers = $wpdb->prefix . 'rishe_treasury_providers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, p.code AS provider_code FROM {$links} l
             INNER JOIN {$providers} p ON p.id = l.provider_id WHERE l.id = %d",
            $paymentLinkId
        ), ARRAY_A);

        return is_array($row) ? $this->formatLink($row) : null;
    }

    public function paymentLinkForUpdate(int $paymentLinkId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_payment_links';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $paymentLinkId
        ), ARRAY_A);

        return is_array($row) ? $this->formatLink($row) : null;
    }

    public function paymentLinkByProviderReference(string $providerCode, string $providerLinkId): ?array
    {
        global $wpdb;

        $links = $wpdb->prefix . 'rishe_payment_links';
        $providers = $wpdb->prefix . 'rishe_treasury_providers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, p.code AS provider_code FROM {$links} l
             INNER JOIN {$providers} p ON p.id = l.provider_id
             WHERE p.code = %s AND l.provider_link_id = %s FOR UPDATE",
            $providerCode,
            $providerLinkId
        ), ARRAY_A);

        return is_array($row) ? $this->formatLink($row) : null;
    }

    public function activatePaymentLink(int $paymentLinkId, array $providerResult): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_payment_links',
            [
                'status' => 'active',
                'provider_link_id' => $providerResult['provider_link_id'],
                'payment_url' => $providerResult['payment_url'],
                'expires_at' => $providerResult['expires_at'],
                'activated_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $paymentLinkId, 'status' => 'creating'],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to activate payment link.');
        }
    }

    public function transitionPaymentLink(int $paymentLinkId, string $status, ?int $transactionId = null): void
    {
        global $wpdb;

        $data = ['status' => $status, 'updated_at' => current_time('mysql', true)];
        $formats = ['%s', '%s'];
        if ($status === 'paid') {
            $data['paid_transaction_id'] = $transactionId;
            $data['paid_at'] = current_time('mysql', true);
            $formats[] = '%d';
            $formats[] = '%s';
        }
        $updated = $wpdb->update(
            $wpdb->prefix . 'rishe_payment_links',
            $data,
            ['id' => $paymentLinkId],
            $formats,
            ['%d']
        );
        if ($updated === false) {
            throw new RuntimeException('Unable to transition payment link: ' . $wpdb->last_error);
        }
    }

    public function importTransaction(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_treasury_transactions';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE treasury_account_id = %d AND external_transaction_id = %s FOR UPDATE",
            $data['treasury_account_id'],
            $data['external_transaction_id']
        ), ARRAY_A);
        if (is_array($existing)) {
            if (
                (string) $existing['direction'] !== (string) $data['direction']
                || (int) $existing['amount_irr'] !== (int) $data['amount_irr']
            ) {
                throw new TreasuryDomainException('External treasury transaction was reused with different inputs.');
            }

            return ['id' => (int) $existing['id'], 'idempotent' => true];
        }

        $id = $this->insert('rishe_treasury_transactions', [
            'public_id' => wp_generate_uuid4(),
            'treasury_account_id' => $data['treasury_account_id'],
            'direction' => $data['direction'],
            'amount_irr' => $data['amount_irr'],
            'transaction_at' => $data['transaction_at'],
            'value_date' => $data['value_date'],
            'external_transaction_id' => $data['external_transaction_id'],
            'reference' => $data['reference'],
            'counterparty_name' => $data['counterparty_name'],
            'counterparty_iban' => $data['counterparty_iban'],
            'description' => $data['description'],
            'source' => $data['source'],
            'raw_hash' => $data['raw_hash'],
            'correlation_id' => $data['correlation_id'],
            'imported_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], [
            '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
        ], 'treasury transaction');

        return ['id' => $id, 'idempotent' => false];
    }

    public function transactionForUpdate(int $transactionId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_treasury_transactions';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
            $transactionId
        ), ARRAY_A);

        return is_array($row) ? $this->formatTransaction($row) : null;
    }

    public function matchedAmountForUpdate(int $transactionId): int
    {
        global $wpdb;

        $matches = $wpdb->prefix . 'rishe_reconciliation_matches';
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_irr), 0) FROM {$matches} WHERE treasury_transaction_id = %d",
            $transactionId
        ));

        return (int) $value;
    }

    public function createMatch(array $data): int
    {
        return $this->insert('rishe_reconciliation_matches', [
            'public_id' => wp_generate_uuid4(),
            'treasury_transaction_id' => $data['treasury_transaction_id'],
            'match_type' => $data['match_type'],
            'entity_id' => $data['entity_id'],
            'amount_irr' => $data['amount_irr'],
            'matched_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%s', '%d', '%d', '%d', '%s'], 'reconciliation match');
    }

    public function createSettlement(array $data): int
    {
        return $this->insert('rishe_treasury_settlements', [
            'public_id' => wp_generate_uuid4(),
            'provider_id' => $data['provider_id'],
            'treasury_account_id' => $data['treasury_account_id'],
            'external_settlement_id' => $data['external_settlement_id'],
            'gross_amount_irr' => $data['gross_amount_irr'],
            'fee_amount_irr' => $data['fee_amount_irr'],
            'net_amount_irr' => $data['net_amount_irr'],
            'settled_at' => $data['settled_at'],
            'raw_hash' => $data['raw_hash'],
            'created_by' => $data['actor_user_id'],
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s'], 'treasury settlement');
    }

    public function accounts(array $filters): array
    {
        return $this->simpleList('rishe_treasury_accounts', $filters, ['type', 'is_active']);
    }

    public function providers(array $filters): array
    {
        $rows = $this->simpleList('rishe_treasury_providers', $filters, ['adapter', 'is_active']);

        return array_map([$this, 'formatProvider'], $rows);
    }

    public function paymentLinks(array $filters): array
    {
        return array_map([$this, 'formatLink'], $this->simpleList(
            'rishe_payment_links',
            $filters,
            ['provider_id', 'sales_order_id', 'status']
        ));
    }

    public function transactions(array $filters): array
    {
        return array_map([$this, 'formatTransaction'], $this->simpleList(
            'rishe_treasury_transactions',
            $filters,
            ['treasury_account_id', 'direction', 'source']
        ));
    }

    public function settlements(array $filters): array
    {
        return $this->simpleList('rishe_treasury_settlements', $filters, ['provider_id', 'treasury_account_id']);
    }

    /** @return array<string, mixed>|null */
    private function row(string $suffix, int $id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert(string $suffix, array $data, array $formats, string $entity): int
    {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . $suffix, $data, $formats);
        if ($inserted === false) {
            throw new RuntimeException('Unable to create ' . $entity . ': ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowed
     * @return list<array<string, mixed>>
     */
    private function simpleList(string $suffix, array $filters, array $allowed): array
    {
        global $wpdb;

        $table = $wpdb->prefix . $suffix;
        $clauses = ['1=1'];
        $args = [];
        foreach ($allowed as $field) {
            if (($filters[$field] ?? null) === null || $filters[$field] === '') {
                continue;
            }
            $format = str_ends_with($field, '_id') || $field === 'is_active' ? '%d' : '%s';
            $clauses[] = "{$field} = {$format}";
            $args[] = $filters[$field];
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $clauses) . ' ORDER BY id DESC LIMIT 250';
        $rows = $wpdb->get_results($args === [] ? $sql : $wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatProvider(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['treasury_account_id'] = (int) $row['treasury_account_id'];
        $row['is_active'] = (bool) $row['is_active'];
        $config = json_decode((string) $row['config_json'], true);
        $row['config'] = is_array($config) ? $config : [];
        unset($row['config_json']);

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatLink(array $row): array
    {
        $integerFields = [
            'id', 'provider_id', 'treasury_account_id', 'sales_order_id', 'customer_id', 'amount_irr',
            'paid_transaction_id', 'created_by',
        ];
        foreach ($integerFields as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatTransaction(array $row): array
    {
        foreach (['id', 'treasury_account_id', 'amount_irr', 'imported_by'] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }
}
