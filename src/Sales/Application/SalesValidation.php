<?php

declare(strict_types=1);

namespace Rishe\Sales\Application;

use DateTimeImmutable;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Sales\Domain\Exception\SalesDomainException;
use RuntimeException;

trait SalesValidation
{
    private function normalizeLines(array $rawLines, string $channel): array
    {
        $lines = [];
        foreach (array_values($rawLines) as $rawLine) {
            if (!is_array($rawLine)) {
                throw new SalesDomainException('Each order line must be an object.');
            }
            $productId = $this->positiveId($rawLine['product_id'] ?? null, 'lines.product_id');
            $product = $this->activeProduct($productId);
            $quantity = Quantity::fromInput($rawLine['quantity'] ?? null);
            $explicitPrice = $rawLine['unit_price_irr'] ?? null;
            $unitPrice = $explicitPrice === null || $explicitPrice === ''
                ? $this->repository->activeChannelPrice($productId, $channel, gmdate('Y-m-d H:i:s'))
                : $this->nonNegativeMoney($explicitPrice, 'lines.unit_price_irr');
            if ($unitPrice === null) {
                throw new SalesDomainException('No active channel price exists for an order line.');
            }

            $lines[] = [
                'product_id' => $productId,
                'sku_snapshot' => (string) $product['sku'],
                'name_snapshot' => (string) $product['name'],
                'quantity_scaled' => $quantity->scaled(),
                'unit_price_irr' => $unitPrice,
                'line_discount_irr' => $this->nonNegativeMoney(
                    $rawLine['line_discount_irr'] ?? 0,
                    'lines.line_discount_irr'
                ),
            ];
        }

        return $lines;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function customerPayload(array $data): array
    {
        $channel = $data['channel'] ?? null;

        return [
            'mobile_normalized' => $this->mobiles->normalize($data['mobile'] ?? null),
            'first_name' => $this->nullableText($data['first_name'] ?? null, 100),
            'last_name' => $this->nullableText($data['last_name'] ?? null, 100),
            'email' => $this->nullableEmail($data['email'] ?? null),
            'channel' => $channel === null || $channel === '' ? null : $this->channel($channel),
            'external_customer_id' => $this->nullableText($data['external_customer_id'] ?? null, 191),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function activeProduct(int $productId): array
    {
        $product = $this->repository->product($productId);
        if ($product === null || !(bool) ($product['is_active'] ?? false)) {
            throw new SalesDomainException('Order product is missing or inactive.');
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function requireOrder(int $orderId): array
    {
        $order = $this->repository->order($orderId);
        if ($order === null) {
            throw new RuntimeException('Sales order not found.');
        }

        return $order;
    }

    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new SalesDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function channel(mixed $value): string
    {
        $channel = strtolower(trim((string) $value));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new SalesDomainException('Sales channel is invalid.');
        }

        return $channel;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SalesDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'filter');
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new SalesDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function nullableMoney(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeMoney($value, $field);
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        return $this->nonNegativeMoney($value, $field);
    }

    private function requiredCode(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '' || strlen($code) > 100 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
            throw new SalesDomainException('Code must contain only letters, digits, dot, dash, or underscore.');
        }

        return $code;
    }

    private function nullableCode(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->requiredCode($value);
    }

    private function requiredName(mixed $value): string
    {
        $name = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '' || $length > 191) {
            throw new SalesDomainException('Name is required and must not exceed 191 characters.');
        }

        return $name;
    }

    private function requiredIdentifier(mixed $value, string $field, int $limit): string
    {
        $identifier = strtolower(trim((string) $value));
        if ($identifier === '' || strlen($identifier) > $limit || !preg_match('/^[a-z0-9._-]+$/', $identifier)) {
            throw new SalesDomainException($field . ' is invalid.');
        }

        return $identifier;
    }

    private function requiredReference(mixed $value, string $field): string
    {
        $reference = trim((string) $value);
        if ($reference === '' || strlen($reference) > 191) {
            throw new SalesDomainException($field . ' is required and must not exceed 191 characters.');
        }

        return $reference;
    }

    private function requiredUuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new SalesDomainException('Order key must be a valid UUID.');
        }

        return $value;
    }

    private function nullableHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $hash = strtolower(trim((string) $value));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new SalesDomainException('Source hash must be a SHA-256 hexadecimal value.');
        }

        return $hash;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $limit) {
            throw new SalesDomainException('Text value exceeds its maximum length.');
        }

        return $text;
    }

    private function nullableEmail(mixed $value): ?string
    {
        $email = $this->nullableText($value, 191);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SalesDomainException('Customer email is invalid.');
        }

        return $email;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $text) {
            throw new SalesDomainException('Datetime must use Y-m-d H:i:s format.');
        }

        return $text;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new SalesDomainException('Date must use Y-m-d format.');
        }

        return $text;
    }
}
