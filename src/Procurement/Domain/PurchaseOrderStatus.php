<?php

declare(strict_types=1);

namespace Rishe\Procurement\Domain;

use Rishe\Procurement\Domain\Exception\ProcurementDomainException;

enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    public function assertCanApprove(): void
    {
        if ($this !== self::DRAFT) {
            throw new ProcurementDomainException('Only a draft purchase order can be approved.');
        }
    }

    public function assertCanReceive(): void
    {
        if (!in_array($this, [self::APPROVED, self::PARTIALLY_RECEIVED], true)) {
            throw new ProcurementDomainException('Only an approved purchase order can receive goods.');
        }
    }

    public function assertCanCancel(bool $hasReceipts): void
    {
        if ($hasReceipts || !in_array($this, [self::DRAFT, self::APPROVED], true)) {
            throw new ProcurementDomainException('Only an unreceived draft or approved purchase order can be cancelled.');
        }
    }

    public function isCommerciallyMutable(): bool
    {
        return $this === self::DRAFT;
    }
}
