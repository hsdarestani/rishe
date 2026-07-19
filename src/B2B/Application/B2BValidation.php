<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use DateTimeImmutable;
use Rishe\B2B\Domain\Exception\B2BDomainException;
use Rishe\Inventory\Domain\Quantity;

trait B2BValidation
{
    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new B2BDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new B2BDomainException($field . ' must be a positive integer.');
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

    private function positiveMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new B2BDomainException($field . ' must be a positive integer in IRR.');
        }

        return (int) $amount;
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new B2BDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function requiredCode(mixed $value): string
    {
        $text = strtoupper(trim((string) $value));
        if ($text === '' || strlen($text) > 100 || !preg_match('/^[A-Z0-9._-]+$/', $text)) {
            throw new B2BDomainException('Code must contain only letters, digits, dot, dash, or underscore.');
        }

        return $text;
    }

    private function requiredName(mixed $value): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 191) {
            throw new B2BDomainException('Name is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function requiredReference(mixed $value, string $field, int $max = 191): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new B2BDomainException($field . ' is required and is too long.');
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
            throw new B2BDomainException('Text value exceeds the allowed length.');
        }

        return $text;
    }

    private function dateTime(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new B2BDomainException($field . ' is required.');
        }
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new B2BDomainException($field . ' is invalid.');
        }
    }

    private function fiscalYear(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1300, 'max_range' => 2500]]);
        if ($year === false) {
            throw new B2BDomainException('Fiscal year is outside the supported range.');
        }

        return (int) $year;
    }

    private function rateBps(mixed $value): int
    {
        $rate = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
        if ($rate === false) {
            throw new B2BDomainException('Commission rate must be between 0 and 10000 basis points.');
        }

        return (int) $rate;
    }

    private function quantity(mixed $value): Quantity
    {
        return Quantity::fromInput($value);
    }

    private function scaledToDecimal(int $scaled): string
    {
        $whole = intdiv($scaled, Quantity::SCALE);
        $fraction = str_pad((string) ($scaled % Quantity::SCALE), 4, '0', STR_PAD_LEFT);

        return $whole . '.' . $fraction;
    }

    /** @return array<string, mixed> */
    private function requireAccount(int $accountId, bool $forUpdate = false): array
    {
        $account = $forUpdate
            ? $this->repository->accountForUpdate($accountId)
            : $this->repository->account($accountId);
        if ($account === null) {
            throw new B2BDomainException('B2B account not found.');
        }
        if ((string) $account['status'] !== 'active') {
            throw new B2BDomainException('B2B account is not active.');
        }

        return $account;
    }
}
