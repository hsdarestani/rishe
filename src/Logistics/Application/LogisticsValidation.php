<?php

declare(strict_types=1);

namespace Rishe\Logistics\Application;

use DateTimeImmutable;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Domain\PackageMetrics;

trait LogisticsValidation
{
    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new LogisticsDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new LogisticsDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'identifier');
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new LogisticsDomainException($field . ' must be a non-negative integer IRR value.');
        }

        return (int) $amount;
    }

    private function positiveMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new LogisticsDomainException($field . ' must be a positive integer IRR value.');
        }

        return (int) $amount;
    }

    private function requiredText(mixed $value, string $field, int $max = 191): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > $max) {
            throw new LogisticsDomainException($field . ' is required and is too long.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max = 191): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $max) {
            throw new LogisticsDomainException('Text value exceeds the allowed length.');
        }

        return $text;
    }

    private function code(mixed $value, string $field = 'code'): string
    {
        $code = strtolower(trim((string) $value));
        if ($code === '' || strlen($code) > 50 || !preg_match('/^[a-z0-9._-]+$/', $code)) {
            throw new LogisticsDomainException($field . ' must contain only lowercase letters, digits, dot, dash, or underscore.');
        }

        return $code;
    }

    private function dateTime(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new LogisticsDomainException($field . ' is required.');
        }
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new LogisticsDomainException($field . ' is invalid.');
        }
    }

    /** @param mixed $value @return array<string, mixed> */
    private function address(mixed $value, string $prefix): array
    {
        if (!is_array($value)) {
            throw new LogisticsDomainException($prefix . ' address must be an object.');
        }

        return [
            'name' => $this->requiredText($value['name'] ?? null, $prefix . '.name'),
            'mobile' => $this->requiredText($value['mobile'] ?? null, $prefix . '.mobile', 30),
            'province' => $this->requiredText($value['province'] ?? null, $prefix . '.province', 100),
            'city' => $this->requiredText($value['city'] ?? null, $prefix . '.city', 100),
            'postal_code' => $this->nullableText($value['postal_code'] ?? null, 20),
            'address' => $this->requiredText($value['address'] ?? null, $prefix . '.address', 1000),
            'latitude' => $this->coordinate($value['latitude'] ?? null, -90, 90),
            'longitude' => $this->coordinate($value['longitude'] ?? null, -180, 180),
        ];
    }

    private function coordinate(mixed $value, float $min, float $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new LogisticsDomainException('Geographic coordinate must be numeric.');
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new LogisticsDomainException('Geographic coordinate is outside its valid range.');
        }

        return number_format($number, 7, '.', '');
    }

    /** @param mixed $raw @return list<array<string, int|string|null>> */
    private function packages(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new LogisticsDomainException('Shipment requires at least one package.');
        }
        $packages = [];
        foreach (array_values($raw) as $index => $item) {
            if (!is_array($item)) {
                throw new LogisticsDomainException('Package must be an object.');
            }
            $metrics = new PackageMetrics(
                $this->positiveInteger($item['weight_grams'] ?? null, 'weight_grams'),
                $this->positiveInteger($item['length_mm'] ?? null, 'length_mm'),
                $this->positiveInteger($item['width_mm'] ?? null, 'width_mm'),
                $this->positiveInteger($item['height_mm'] ?? null, 'height_mm'),
                $this->positiveInteger($item['quantity'] ?? 1, 'quantity')
            );
            $packages[] = [
                'sequence' => $index + 1,
                'weight_grams' => $metrics->weightGrams,
                'length_mm' => $metrics->lengthMm,
                'width_mm' => $metrics->widthMm,
                'height_mm' => $metrics->heightMm,
                'quantity' => $metrics->quantity,
                'total_weight_grams' => $metrics->totalWeightGrams(),
                'volumetric_weight_grams' => $metrics->volumetricWeightGrams(),
                'contents' => $this->nullableText($item['contents'] ?? null, 500),
            ];
        }

        return $packages;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new LogisticsDomainException($field . ' must be a positive integer.');
        }

        return (int) $number;
    }

    /** @return array<string, mixed> */
    private function requireCarrier(int $carrierId): array
    {
        $carrier = $this->repository->carrier($carrierId);
        if ($carrier === null || !(bool) ($carrier['is_active'] ?? false)) {
            throw new LogisticsDomainException('Carrier is missing or inactive.');
        }

        return $carrier;
    }
}
