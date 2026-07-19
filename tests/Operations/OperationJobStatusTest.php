<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Operations\Domain\OperationJobStatus;

final class OperationJobStatusTest extends TestCase
{
    public function testCompletedJobCannotMoveBackToPending(): void
    {
        $this->expectException(OperationsDomainException::class);
        OperationJobStatus::COMPLETED->assertCanTransitionTo(OperationJobStatus::PENDING);
    }

    public function testRetryWaitCanBeClaimed(): void
    {
        OperationJobStatus::RETRY_WAIT->assertCanTransitionTo(OperationJobStatus::RUNNING);
        self::assertTrue(true);
    }
}
