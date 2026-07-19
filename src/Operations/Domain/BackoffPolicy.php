<?php

declare(strict_types=1);

namespace Rishe\Operations\Domain;

use Rishe\Operations\Domain\Exception\OperationsDomainException;

final class BackoffPolicy
{
    public function __construct(
        private int $baseSeconds = 60,
        private int $maximumSeconds = 3600
    ) {
        if ($baseSeconds < 1 || $maximumSeconds < $baseSeconds) {
            throw new OperationsDomainException('Backoff configuration is invalid.');
        }
    }

    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            throw new OperationsDomainException('Attempt number must be positive.');
        }

        $exponent = min(20, $attempt - 1);
        $delay = $this->baseSeconds * (2 ** $exponent);

        return min($this->maximumSeconds, $delay);
    }
}
