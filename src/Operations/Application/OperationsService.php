<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\BackoffPolicy;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;

final class OperationsService
{
    use OperationsEnqueueOperations;
    use OperationsExecutionOperations;
    use OperationsControlOperations;
    use OperationsFailureOperations;
    use OperationsValidation;

    public function __construct(
        private OperationsRepository $repository,
        private JobHandlerRegistry $handlers,
        private JobScheduler $scheduler,
        private TransactionRunner $transactions,
        private AuditRecorder $audit,
        private BackoffPolicy $backoff
    ) {
    }
}
