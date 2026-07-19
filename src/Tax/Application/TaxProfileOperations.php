<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

use Rishe\Tax\Domain\Exception\TaxDomainException;

trait TaxProfileOperations
{
    public function upsertProfile(array $data, int $actorUserId): array
    {
        $memoryId = strtoupper($this->requiredText($data['fiscal_memory_id'] ?? null, 'fiscal_memory_id', 6));
        if (!preg_match('/^[A-Z0-9]{6}$/', $memoryId)) {
            throw new TaxDomainException('Fiscal memory id must contain exactly six Latin letters or digits.');
        }
        $taxpayerType = filter_var($data['taxpayer_type'] ?? 2, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4],
        ]);
        if ($taxpayerType === false) {
            throw new TaxDomainException('Taxpayer type is invalid.');
        }
        $credentials = $this->jsonObject($data['credentials'] ?? [], 'credentials');
        $privateKey = $this->requiredText($data['private_key_pem'] ?? null, 'private_key_pem', 20000);
        $config = $this->jsonObject($data['gateway_config'] ?? [], 'gateway_config');
        $payload = [
            'code' => strtoupper($this->requiredText($data['code'] ?? null, 'code', 100)),
            'name' => $this->requiredText($data['name'] ?? null, 'name', 191),
            'taxpayer_type' => (int) $taxpayerType,
            'national_id' => $this->requiredText($data['national_id'] ?? null, 'national_id', 30),
            'economic_code' => $this->requiredText($data['economic_code'] ?? null, 'economic_code', 30),
            'fiscal_memory_id' => $memoryId,
            'branch_code' => $this->nullableText($data['branch_code'] ?? null, 20),
            'default_invoice_type' => (int) ($data['default_invoice_type'] ?? 1),
            'default_pattern' => (int) ($data['default_pattern'] ?? 1),
            'gateway_type' => strtolower($this->requiredText($data['gateway_type'] ?? 'http_json', 'gateway_type', 30)),
            'gateway_config_json' => json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'credentials_ciphertext' => $this->vault->sealArray($credentials),
            'private_key_ciphertext' => $this->vault->seal($privateKey),
            'certificate_pem' => $this->nullableText($data['certificate_pem'] ?? null, 20000),
            'key_id' => $this->nullableText($data['key_id'] ?? null, 191),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->upsertProfile($payload);
            $this->audit->record(
                $result['created'] ? 'tax.profile.created' : 'tax.profile.updated',
                'tax_profile',
                (string) $result['id'],
                ['code' => $payload['code'], 'fiscal_memory_id' => $payload['fiscal_memory_id']]
            );

            return $result;
        });
    }

    public function profiles(): array
    {
        return $this->repository->profiles();
    }

    public function profile(int $profileId): array
    {
        $profile = $this->requireProfile($this->positiveId($profileId, 'profile_id'));
        unset($profile['credentials_ciphertext'], $profile['private_key_ciphertext']);

        return $profile;
    }

    public function upsertProductMapping(array $data, int $actorUserId): array
    {
        $profileId = $this->positiveId($data['profile_id'] ?? null, 'profile_id');
        $this->requireProfile($profileId);
        $vatRate = filter_var($data['vat_rate_basis_points'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 10000],
        ]);
        if ($vatRate === false) {
            throw new TaxDomainException('VAT rate must be between 0 and 10000 basis points.');
        }

        return $this->transactions->run(function () use ($data, $profileId, $vatRate, $actorUserId): array {
            $result = $this->repository->upsertProductMapping([
                'profile_id' => $profileId,
                'product_id' => $this->positiveId($data['product_id'] ?? null, 'product_id'),
                'tax_product_id' => $this->requiredText($data['tax_product_id'] ?? null, 'tax_product_id', 50),
                'measurement_unit' => $this->requiredText($data['measurement_unit'] ?? null, 'measurement_unit', 30),
                'vat_rate_basis_points' => (int) $vatRate,
                'description' => $this->nullableText($data['description'] ?? null, 191),
                'actor_user_id' => $this->actor($actorUserId),
            ]);
            $this->audit->record('tax.product_mapping.saved', 'tax_product_mapping', (string) $result['id'], [
                'profile_id' => $profileId,
                'product_id' => (int) $data['product_id'],
            ]);

            return $result;
        });
    }

    public function productMappings(int $profileId): array
    {
        $this->requireProfile($profileId);

        return $this->repository->productMappings($profileId);
    }
}
