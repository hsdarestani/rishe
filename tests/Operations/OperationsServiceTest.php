<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Application\OperationsService;
use Rishe\Operations\Domain\BackoffPolicy;
use Rishe\Operations\Infrastructure\StaticJobHandlerRegistry;
use Rishe\Tests\Operations\Fakes\ConfigurableJobHandler;
use Rishe\Tests\Operations\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Operations\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Operations\Fakes\InMemoryJobScheduler;
use Rishe\Tests\Operations\Fakes\InMemoryOperationsRepository;

final class OperationsServiceTest extends TestCase
{
    public function testEnqueueIsIdempotentAndSuccessfulJobCompletes(): void
    {
        $repository = new InMemoryOperationsRepository();
        $scheduler = new InMemoryJobScheduler();
        $handler = new ConfigurableJobHandler();
        $service = $this->service($repository, $scheduler, $handler);
        $payload = [
            'job_type' => 'test.execute',
            'aggregate_type' => 'sample',
            'aggregate_id' => '42',
            'idempotency_key' => 'sample-42',
            'payload' => ['value' => 42],
        ];

        $first = $service->enqueue($payload, 7);
        $second = $service->enqueue($payload, 7);
        $completed = $service->execute((int) $first['id']);

        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertCount(1, $scheduler->scheduled);
        self::assertSame('completed', $completed['status']);
        self::assertTrue($completed['result']['handled']);
        self::assertSame(1, $handler->calls);
    }

    public function testRepeatedFailureSchedulesRetryThenCreatesIncident(): void
    {
        $repository = new InMemoryOperationsRepository();
        $scheduler = new InMemoryJobScheduler();
        $handler = new ConfigurableJobHandler(2);
        $service = $this->service($repository, $scheduler, $handler);
        $job = $service->enqueue([
            'job_type' => 'test.execute',
            'aggregate_type' => 'sample',
            'aggregate_id' => '99',
            'idempotency_key' => 'sample-99',
            'payload' => [],
            'max_attempts' => 2,
        ], 7);

        $firstFailure = $service->execute((int) $job['id']);
        self::assertSame('retry_wait', $firstFailure['status']);
        self::assertCount(2, $scheduler->scheduled);

        $repository->jobs[(int) $job['id']]['scheduled_at'] = gmdate('Y-m-d H:i:s', time() - 1);
        $terminal = $service->execute((int) $job['id']);

        self::assertSame('failed', $terminal['status']);
        self::assertCount(1, $repository->incidents);
        self::assertSame(2, $handler->calls);
    }

    public function testMaintenanceRecoversExpiredWorkerLock(): void
    {
        $repository = new InMemoryOperationsRepository();
        $scheduler = new InMemoryJobScheduler();
        $service = $this->service($repository, $scheduler, new ConfigurableJobHandler());
        $job = $service->enqueue([
            'job_type' => 'test.execute',
            'aggregate_type' => 'sample',
            'aggregate_id' => '77',
            'idempotency_key' => 'sample-77',
            'payload' => [],
            'max_attempts' => 3,
        ], 7);
        $repository->jobs[(int) $job['id']]['status'] = 'running';
        $repository->jobs[(int) $job['id']]['attempts'] = 1;
        $repository->jobs[(int) $job['id']]['locked_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
        $scheduler->scheduled = [];

        $result = $service->recoverStaleJobs(900);

        self::assertSame(1, $result['recovered']);
        self::assertSame('retry_wait', $repository->jobs[(int) $job['id']]['status']);
        self::assertCount(1, $scheduler->scheduled);
    }

    private function service(
        InMemoryOperationsRepository $repository,
        InMemoryJobScheduler $scheduler,
        ConfigurableJobHandler $handler
    ): OperationsService {
        return new OperationsService(
            $repository,
            new StaticJobHandlerRegistry([$handler]),
            $scheduler,
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder(),
            new BackoffPolicy(1, 2)
        );
    }
}
