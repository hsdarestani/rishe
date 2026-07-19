<?php

declare(strict_types=1);

namespace Rishe\Accounting\Domain\Journal;

enum VoucherStatus: string
{
    case DRAFT = 'draft';
    case TEMPORARY = 'temporary';
    case POSTED = 'posted';
    case REVERSED = 'reversed';

    public function isMutable(): bool
    {
        return $this === self::DRAFT || $this === self::TEMPORARY;
    }
}
