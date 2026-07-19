<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;

trait TreasuryValidation
{
    /** @return array<string, mixed> */
    private function requireActiveAccount(int $accountId): array
    {
        $account = $this->repository->account($accountId);
        if ($account === null || !(bool) ($account['is_active'] ?? false)) {
            throw new TreasuryDomainException('Treasury account is missing or inactive.');
        }

        return $account;
    }

    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new TreasuryDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new TreasuryDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'id');
    }

    private function positiveMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($amount === false) {
            throw new TreasuryDomainException($field . ' must be a positive integer in IRR.');
        }

        return (int) $amount;
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new TreasuryDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function optionalMoney(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveMoney($value, 'amount_irr');
    }

    private function code(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 100 || !preg_match('/^[A-Za-z0-9._-]+$/', $text)) {
            throw new TreasuryDomainException('Code contains invalid characters.');
        }

        return $text;
    }

    private function name(mixed $value): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 191) {
            throw new TreasuryDomainException('Name is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function requiredReference(mixed $value, string $field, int $max = 191): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new TreasuryDomainException($field . ' is required and exceeds its supported length.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (strlen($text) > $max) {
            throw new TreasuryDomainException('Text exceeds the supported length.');
        }

        return $text;
    }

    private function nullableIdentifier(mixed $value, int $max): ?string
    {
        $text = $this->nullableText($value, $max);
        if ($text === null) {
            return null;
        }
        $text = strtoupper(str_replace([' ', '-'], '', $text));
        if (!preg_match('/^[A-Z0-9]+$/', $text)) {
            throw new TreasuryDomainException('Treasury identifier contains invalid characters.');
        }

        return $text;
    }

    private function nullableHash(mixed $value): ?string
    {
        $text = $this->nullableText($value, 64);
        if ($text !== null && !preg_match('/^[a-f0-9]{64}$/', strtolower($text))) {
            throw new TreasuryDomainException('Hash must be a SHA-256 hexadecimal string.');
        }

        return $text === null ? null : strtolower($text);
    }

    private function requiredUrl(mixed $value, string $field): string
    {
        $url = trim((string) $value);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TreasuryDomainException($field . ' must be a valid absolute URL.');
        }

        return $url;
    }

    private function dateTime(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $text) {
            throw new TreasuryDomainException($field . ' must use Y-m-d H:i:s format.');
        }

        return $text;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->dateTime($value, 'datetime');
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new TreasuryDomainException('Date must use Y-m-d format.');
        }

        return $text;
    }
}
