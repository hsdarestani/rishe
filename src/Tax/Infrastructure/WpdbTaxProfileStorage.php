<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use RuntimeException;

trait WpdbTaxProfileStorage
{
    public function upsertProfile(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_profiles';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s FOR UPDATE",
            $data['code']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        $values = [
            'name' => $data['name'],
            'taxpayer_type' => $data['taxpayer_type'],
            'national_id' => $data['national_id'],
            'economic_code' => $data['economic_code'],
            'fiscal_memory_id' => $data['fiscal_memory_id'],
            'branch_code' => $data['branch_code'],
            'default_invoice_type' => $data['default_invoice_type'],
            'default_pattern' => $data['default_pattern'],
            'gateway_type' => $data['gateway_type'],
            'gateway_config_json' => $data['gateway_config_json'],
            'credentials_ciphertext' => $data['credentials_ciphertext'],
            'private_key_ciphertext' => $data['private_key_ciphertext'],
            'certificate_pem' => $data['certificate_pem'],
            'key_id' => $data['key_id'],
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            if ($wpdb->update($table, $values, ['id' => $existing['id']]) === false) {
                throw new RuntimeException('Unable to update tax profile: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = $this->insert('rishe_tax_profiles', array_merge([
            'public_id' => wp_generate_uuid4(),
            'code' => $data['code'],
            'is_active' => 1,
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
        ], $values), [], 'tax profile');

        return ['id' => $id, 'created' => true];
    }

    public function profile(int $profileId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_profiles';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $profileId), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $raw = $row;
        $formatted = $this->formatProfile($row);
        $formatted['credentials_ciphertext'] = $raw['credentials_ciphertext'];
        $formatted['private_key_ciphertext'] = $raw['private_key_ciphertext'];

        return $formatted;
    }

    public function profiles(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SELECT * FROM ' . $wpdb->prefix . 'rishe_tax_profiles ORDER BY id DESC LIMIT 250',
            ARRAY_A
        );

        return is_array($rows) ? array_map([$this, 'formatProfile'], $rows) : [];
    }

    public function upsertProductMapping(array $data): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_product_mappings';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE profile_id = %d AND product_id = %d FOR UPDATE",
            $data['profile_id'],
            $data['product_id']
        ), ARRAY_A);
        $now = current_time('mysql', true);
        $values = [
            'tax_product_id' => $data['tax_product_id'],
            'measurement_unit' => $data['measurement_unit'],
            'vat_rate_basis_points' => $data['vat_rate_basis_points'],
            'description' => $data['description'],
            'is_active' => 1,
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            if ($wpdb->update($table, $values, ['id' => $existing['id']]) === false) {
                throw new RuntimeException('Unable to update tax product mapping: ' . $wpdb->last_error);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = $this->insert('rishe_tax_product_mappings', array_merge([
            'profile_id' => $data['profile_id'],
            'product_id' => $data['product_id'],
            'created_by' => $data['actor_user_id'],
            'created_at' => $now,
        ], $values), [], 'tax product mapping');

        return ['id' => $id, 'created' => true];
    }

    public function productMapping(int $profileId, int $productId): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_product_mappings';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE profile_id = %d AND product_id = %d",
            $profileId,
            $productId
        ), ARRAY_A);

        return is_array($row) ? $this->formatMapping($row) : null;
    }

    public function productMappings(int $profileId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rishe_tax_product_mappings';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE profile_id = %d ORDER BY id DESC",
            $profileId
        ), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'formatMapping'], $rows) : [];
    }
}
