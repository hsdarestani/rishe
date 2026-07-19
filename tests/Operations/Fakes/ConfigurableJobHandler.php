<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations\Fakes;

use Rishe\Operations\Application\JobHandler;
use RuntimeException;

final class ConfigurableJobHandler implements JobHandler
{
    public int $calls = 0;

    public function __construct(private int $failuresBeforeSuccess = 0)
    {
    }

    public function type(): string
    {
        return 'test.execute';
    }

    public function handle(array $job): array
    {
        ++$this->calls;
        if ($this->calls <= $this->failuresBeforeSuccess) {
            throw new RuntimeException('Planned handler failure.');
        }

        return ['aggregate_id' => $job['aggregate_id'], 'handled' => true];
    }
}
