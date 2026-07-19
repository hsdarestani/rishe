<?php

declare(strict_types=1);

namespace Rishe\Tax\Domain;

use Rishe\Tax\Domain\Exception\TaxDomainException;

enum TaxInvoiceStatus: string
{
    case DRAFT = 'draft';
    case FROZEN = 'frozen';
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CORRECTED = 'corrected';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function assertCanFreeze(): void
    {
        if ($this !== self::DRAFT) {
            throw new TaxDomainException('Only a draft tax invoice can be frozen.');
        }
    }

    public function assertCanSubmit(): void
    {
        if (!in_array($this, [self::FROZEN, self::REJECTED, self::SUBMITTED], true)) {
            throw new TaxDomainException('Tax invoice is not eligible for submission.');
        }
    }

    public function assertCanDerive(): void
    {
        if (!in_array($this, [self::ACCEPTED, self::SUBMITTED], true)) {
            throw new TaxDomainException('Only a submitted or accepted invoice can be corrected, cancelled, or returned.');
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CORRECTED, self::CANCELLED, self::RETURNED], true);
    }
}
