<?php

declare(strict_types=1);

namespace Rishe\Logistics\Domain;

use DateTimeImmutable;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

final class TrackingEvent
{
    public function __construct(
        public readonly string $externalEventId,
        public readonly ShipmentStatus $status,
        public readonly string $occurredAt,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly ?string $rawHash = null
    ) {
        if (trim($externalEventId) === '' || strlen($externalEventId) > 191) {
            throw new LogisticsDomainException('Tracking event id is required and is too long.');
        }
        try {
            new DateTimeImmutable($occurredAt);
        } catch (\Throwable) {
            throw new LogisticsDomainException('Tracking event timestamp is invalid.');
        }
        if ($description !== null && strlen($description) > 500) {
            throw new LogisticsDomainException('Tracking description is too long.');
        }
        if ($location !== null && strlen($location) > 191) {
            throw new LogisticsDomainException('Tracking location is too long.');
        }
        if ($rawHash !== null && !preg_match('/^[a-f0-9]{64}$/', $rawHash)) {
            throw new LogisticsDomainException('Tracking raw hash must be SHA-256.');
        }
    }
}
