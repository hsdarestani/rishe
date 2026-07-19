<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\JobHandler;
use Rishe\Operations\Application\JobHandlerRegistry;
use Rishe\Operations\Domain\Exception\OperationsDomainException;

final class StaticJobHandlerRegistry implements JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @param list<JobHandler> $handlers */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $handler) {
            $type = strtolower(trim($handler->type()));
            if ($type === '' || isset($this->handlers[$type])) {
                throw new OperationsDomainException('Operation job handler registration is invalid.');
            }
            $this->handlers[$type] = $handler;
        }
        ksort($this->handlers);
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlers[strtolower(trim($jobType))]);
    }

    public function handler(string $jobType): JobHandler
    {
        $type = strtolower(trim($jobType));
        if (!isset($this->handlers[$type])) {
            throw new OperationsDomainException('Operation job handler is not registered.');
        }

        return $this->handlers[$type];
    }

    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
