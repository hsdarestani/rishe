<?php

declare(strict_types=1);

namespace Rishe\Treasury\Domain;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;

enum PaymentLinkStatus: string
{
    case CREATING = 'creating';
    case ACTIVE = 'active';
    case PAID = 'paid';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::CREATING => in_array($next, [self::ACTIVE, self::FAILED, self::CANCELLED], true),
            self::ACTIVE => in_array($next, [self::PAID, self::FAILED, self::EXPIRED, self::CANCELLED], true),
            self::FAILED => $next === self::ACTIVE,
            self::PAID, self::EXPIRED, self::CANCELLED => false,
        };
    }

    public function assertTransition(self $next): void
    {
        if (!$this->canTransitionTo($next)) {
            throw new TreasuryDomainException(
                sprintf('Payment link cannot transition from %s to %s.', $this->value, $next->value)
            );
        }
    }
}
