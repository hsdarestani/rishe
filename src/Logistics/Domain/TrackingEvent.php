<?php

declare(strict_types=1);

namespace Rishe\Logistics\Domain;

use DateTimeImmutable;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

final readonly class TrackingEvent
{
    public function __construct(
        public string $externalEventId,
        public ShipmentStatus $status,
        public string $occurredAt,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $rawHash = null
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
