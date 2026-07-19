<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

use DateTimeImmutable;
use Rishe\Tax\Domain\Exception\TaxDomainException;

trait TaxValidation
{
    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new TaxDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new TaxDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new TaxDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function requiredText(mixed $value, string $field, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > $max) {
            throw new TaxDomainException($field . ' is required and is too long.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = trim((string) $value);
        if (strlen($text) > $max) {
            throw new TaxDomainException('Text value exceeds the allowed length.');
        }

        return $text;
    }

    private function dateTime(mixed $value, string $field): string
    {
        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new TaxDomainException($field . ' is invalid.');
        }
    }

    private function jsonObject(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new TaxDomainException($field . ' must be an object.');
        }

        return $value;
    }

    private function requireProfile(int $profileId): array
    {
        $profile = $this->repository->profile($profileId);
        if ($profile === null || !(bool) ($profile['is_active'] ?? false)) {
            throw new TaxDomainException('Tax profile is missing or inactive.');
        }

        return $profile;
    }

    private function requireInvoice(int $invoiceId): array
    {
        $invoice = $this->repository->invoice($invoiceId);
        if ($invoice === null) {
            throw new TaxDomainException('Tax invoice not found.');
        }

        return $invoice;
    }
}
