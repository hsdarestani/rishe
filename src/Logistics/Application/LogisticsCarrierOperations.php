<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use RuntimeException;

trait LogisticsCarrierOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function upsertCarrier(array $data, int $actorUserId): array
    {
        $code = $this->code($data['code'] ?? null);
        $supported = ['post', 'tipax', 'snapp', 'alopeyk', 'custom'];
        if (!in_array($code, $supported, true)) {
            throw new LogisticsDomainException('Carrier code must be post, tipax, snapp, alopeyk, or custom.');
        }
        $config = $data['config'] ?? [];
        $credentials = $data['credentials'] ?? [];
        if (!is_array($config) || !is_array($credentials)) {
            throw new LogisticsDomainException('Carrier config and credentials must be objects.');
        }
        $webhookSecret = $this->requiredText($data['webhook_secret'] ?? null, 'webhook_secret', 500);
        $payload = [
            'code' => $code,
            'name' => $this->requiredText($data['name'] ?? null, 'name'),
            'driver' => 'http_json',
            'mode' => in_array(($data['mode'] ?? 'sandbox'), ['sandbox', 'production'], true)
                ? (string) ($data['mode'] ?? 'sandbox')
                : throw new LogisticsDomainException('Carrier mode must be sandbox or production.'),
            'base_url' => rtrim($this->requiredText($data['base_url'] ?? null, 'base_url', 500), '/'),
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'credentials_ciphertext' => $this->vault->sealArray($credentials),
            'webhook_secret_ciphertext' => $this->vault->seal($webhookSecret),
            'shipping_expense_subsidiary_ledger_id' => $this->optionalPositiveId(
                $data['shipping_expense_subsidiary_ledger_id'] ?? null
            ),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->upsertCarrier($payload);
            $this->audit->record(
                $result['created'] ? 'logistics.carrier.created' : 'logistics.carrier.updated',
                'logistics_carrier',
                (string) $result['id'],
                ['code' => $payload['code'], 'mode' => $payload['mode']]
            );

            return $this->carrier((int) $result['id']);
        });
    }

    /** @return array<string, mixed> */
    public function carrier(int $carrierId): array
    {
        $carrier = $this->repository->carrier($this->positiveId($carrierId, 'carrier_id'));
        if ($carrier === null) {
            throw new RuntimeException('Carrier not found.');
        }
        unset($carrier['credentials_ciphertext'], $carrier['webhook_secret_ciphertext']);

        return $carrier;
    }

    /** @return list<array<string, mixed>> */
    public function carriers(array $filters = []): array
    {
        $rows = $this->repository->carriers([
            'is_active' => isset($filters['is_active']) ? (int) (bool) $filters['is_active'] : null,
            'code' => $this->nullableText($filters['code'] ?? null, 50),
        ]);
        foreach ($rows as &$row) {
            unset($row['credentials_ciphertext'], $row['webhook_secret_ciphertext']);
        }
        unset($row);

        return $rows;
    }
}
