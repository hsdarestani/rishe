<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

use Rishe\Sales\Domain\Exception\SalesDomainException;
use RuntimeException;

trait SalesCatalogOperations
{
    public function upsertCustomer(array $data, int $actorUserId): array
    {
        $payload = $this->customerPayload($data);
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($payload, $actor): array {
            $result = $this->repository->upsertCustomer($payload);
            $this->audit->record(
                $result['created'] ? 'crm.customer.created' : 'crm.customer.updated',
                'customer',
                (string) $result['id'],
                ['mobile' => $payload['mobile_normalized'], 'actor_user_id' => $actor]
            );

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function createChannelPrice(array $data, int $actorUserId): int
    {
        $channel = $this->channel($data['channel'] ?? null);
        $payload = [
            'product_id' => $this->positiveId($data['product_id'] ?? null, 'product_id'),
            'channel' => $channel,
            'unit_price_irr' => $this->nonNegativeMoney($data['unit_price_irr'] ?? null, 'unit_price_irr'),
            'starts_at' => $this->nullableDateTime($data['starts_at'] ?? null),
            'ends_at' => $this->nullableDateTime($data['ends_at'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];
        if (
            $payload['starts_at'] !== null
            && $payload['ends_at'] !== null
            && $payload['starts_at'] > $payload['ends_at']
        ) {
            throw new SalesDomainException('Channel price starts_at cannot follow ends_at.');
        }

        return $this->transactions->run(function () use ($payload): int {
            $product = $this->activeProduct((int) $payload['product_id']);
            $id = $this->repository->createChannelPrice($payload);
            $this->audit->record(
                'sales.channel_price.created',
                'channel_price',
                (string) $id,
                [
                    'product_id' => (int) $product['id'],
                    'channel' => $payload['channel'],
                    'unit_price_irr' => $payload['unit_price_irr'],
                ]
            );

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function createPromotion(array $data, int $actorUserId): int
    {
        $type = strtolower(trim((string) ($data['discount_type'] ?? '')));
        if (!in_array($type, ['fixed', 'percent'], true)) {
            throw new SalesDomainException('Promotion type must be fixed or percent.');
        }

        $value = $this->nonNegativeMoney($data['value'] ?? null, 'value');
        if ($type === 'percent' && $value > 10000) {
            throw new SalesDomainException('Percentage promotion value must be basis points between zero and 10000.');
        }
        $channel = $data['channel'] ?? null;
        $payload = [
            'code' => $this->requiredCode($data['code'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'discount_type' => $type,
            'value' => $value,
            'max_discount_irr' => $this->nullableMoney($data['max_discount_irr'] ?? null, 'max_discount_irr'),
            'min_order_irr' => $this->nonNegativeMoney($data['min_order_irr'] ?? 0, 'min_order_irr'),
            'channel' => $channel === null || $channel === '' ? null : $this->channel($channel),
            'starts_at' => $this->nullableDateTime($data['starts_at'] ?? null),
            'ends_at' => $this->nullableDateTime($data['ends_at'] ?? null),
            'usage_limit' => $this->optionalPositiveId($data['usage_limit'] ?? null),
            'per_customer_limit' => $this->optionalPositiveId($data['per_customer_limit'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];
        if (
            $payload['starts_at'] !== null
            && $payload['ends_at'] !== null
            && $payload['starts_at'] > $payload['ends_at']
        ) {
            throw new SalesDomainException('Promotion starts_at cannot follow ends_at.');
        }

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createPromotion($payload);
            $this->audit->record('sales.promotion.created', 'promotion', (string) $id, [
                'code' => $payload['code'],
                'discount_type' => $payload['discount_type'],
                'value' => $payload['value'],
            ]);

            return $id;
        });
    }

    /** @return array<string, mixed> */
    public function customer(int $customerId): array
    {
        $customer = $this->repository->customer($this->positiveId($customerId, 'customer_id'));
        if ($customer === null) {
            throw new RuntimeException('Customer not found.');
        }

        return $customer;
    }
}
