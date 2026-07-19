<?php

declare(strict_types=1);

namespace Rishe\Tax\Domain;

enum TaxInvoiceSubject: int
{
    case ORIGINAL = 1;
    case CORRECTION = 2;
    case CANCELLATION = 3;
    case RETURN = 4;

    public function terminalStatus(): ?TaxInvoiceStatus
    {
        return match ($this) {
            self::CORRECTION => TaxInvoiceStatus::CORRECTED,
            self::CANCELLATION => TaxInvoiceStatus::CANCELLED,
            self::RETURN => TaxInvoiceStatus::RETURNED,
            self::ORIGINAL => null,
        };
    }
}
