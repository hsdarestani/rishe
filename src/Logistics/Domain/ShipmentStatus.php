<?php

declare(strict_types=1);

namespace Rishe\Logistics\Domain;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

enum ShipmentStatus: string
{
    case DRAFT = 'draft';
    case QUOTED = 'quoted';
    case BOOKED = 'booked';
    case LABEL_READY = 'label_ready';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case EXCEPTION = 'exception';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function assertCanQuote(): void
    {
        if (!in_array($this, [self::DRAFT, self::QUOTED], true)) {
            throw new LogisticsDomainException('Only a draft or quoted shipment can be quoted.');
        }
    }

    public function assertCanBook(): void
    {
        if (!in_array($this, [self::DRAFT, self::QUOTED], true)) {
            throw new LogisticsDomainException('Only a draft or quoted shipment can be booked.');
        }
    }

    public function assertCanCancel(): void
    {
        if (!in_array($this, [self::DRAFT, self::QUOTED, self::BOOKED, self::LABEL_READY, self::EXCEPTION], true)) {
            throw new LogisticsDomainException('Shipment can no longer be cancelled.');
        }
    }

    public function assertTransitionTo(self $next): void
    {
        if ($next === $this) {
            return;
        }

        $allowed = match ($this) {
            self::DRAFT => [self::QUOTED, self::BOOKED, self::LABEL_READY, self::CANCELLED],
            self::QUOTED => [self::BOOKED, self::LABEL_READY, self::CANCELLED],
            self::BOOKED => [self::LABEL_READY, self::IN_TRANSIT, self::EXCEPTION, self::CANCELLED],
            self::LABEL_READY => [self::IN_TRANSIT, self::EXCEPTION, self::CANCELLED],
            self::IN_TRANSIT => [self::DELIVERED, self::EXCEPTION, self::RETURNED],
            self::EXCEPTION => [self::IN_TRANSIT, self::DELIVERED, self::RETURNED, self::CANCELLED],
            self::DELIVERED => [self::RETURNED],
            self::CANCELLED, self::RETURNED => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new LogisticsDomainException(
                sprintf('Invalid shipment transition from %s to %s.', $this->value, $next->value)
            );
        }
    }
}
