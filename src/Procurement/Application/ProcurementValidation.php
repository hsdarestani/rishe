<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use DateTimeImmutable;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;

trait ProcurementValidation
{
    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new ProcurementDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ProcurementDomainException($field . ' must be a positive integer.');
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
            throw new ProcurementDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function positiveMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new ProcurementDomainException($field . ' must be a positive integer in IRR.');
        }

        return (int) $amount;
    }

    private function requiredCode(mixed $value): string
    {
        $text = strtoupper(trim((string) $value));
        if ($text === '' || strlen($text) > 100 || !preg_match('/^[A-Z0-9._-]+$/', $text)) {
            throw new ProcurementDomainException('Code must contain only letters, digits, dot, dash, or underscore.');
        }

        return $text;
    }

    private function requiredName(mixed $value): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 191) {
            throw new ProcurementDomainException('Name is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function requiredReference(mixed $value, string $field, int $max = 191): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new ProcurementDomainException($field . ' is required and is too long.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $max) {
            throw new ProcurementDomainException('Text value exceeds the allowed length.');
        }

        return $text;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if ($date === false || $date->format('Y-m-d') !== (string) $value) {
            throw new ProcurementDomainException('Date must use YYYY-MM-DD.');
        }

        return $date->format('Y-m-d');
    }

    private function dateTime(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new ProcurementDomainException($field . ' is required.');
        }
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new ProcurementDomainException($field . ' is invalid.');
        }
    }

    private function fiscalYear(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1300, 'max_range' => 2500]]);
        if ($year === false) {
            throw new ProcurementDomainException('Fiscal year is outside the supported range.');
        }

        return (int) $year;
    }

    private function quantity(mixed $value): Quantity
    {
        return Quantity::fromInput($value);
    }

    /** @return array<string, mixed> */
    private function requireSupplier(int $supplierId): array
    {
        $supplier = $this->repository->supplier($supplierId);
        if ($supplier === null || !(bool) ($supplier['is_active'] ?? false)) {
            throw new ProcurementDomainException('Supplier is missing or inactive.');
        }

        return $supplier;
    }

    /** @return array<string, mixed> */
    private function requireProduct(int $productId): array
    {
        $product = $this->repository->product($productId);
        if ($product === null || !(bool) ($product['is_active'] ?? false)) {
            throw new ProcurementDomainException('Product is missing or inactive.');
        }

        return $product;
    }
}
