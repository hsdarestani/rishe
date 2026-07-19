<?php

declare(strict_types=1);

namespace Rishe\Operations\Domain;

use Rishe\Operations\Domain\Exception\OperationsDomainException;

enum OperationJobStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case RETRY_WAIT = 'retry_wait';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function assertCanTransitionTo(self $next): void
    {
        if ($next === $this) {
            return;
        }

        $allowed = match ($this) {
            self::PENDING => [self::RUNNING, self::CANCELLED],
            self::RUNNING => [self::COMPLETED, self::RETRY_WAIT, self::FAILED],
            self::RETRY_WAIT => [self::RUNNING, self::PENDING, self::CANCELLED],
            self::FAILED => [self::PENDING, self::CANCELLED],
            self::COMPLETED, self::CANCELLED => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new OperationsDomainException(
                sprintf('Operation job cannot transition from %s to %s.', $this->value, $next->value)
            );
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
