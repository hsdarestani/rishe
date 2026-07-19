<?php

declare(strict_types=1);

namespace Rishe\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Infrastructure\Handlers\SystemNoopJobHandler;

final class SystemNoopJobHandlerTest extends TestCase
{
    public function testHandlerReturnsSmallHealthResult(): void
    {
        $result = (new SystemNoopJobHandler())->handle([
            'id' => 19,
            'payload' => ['delay_ms' => 0],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(19, $result['job_id']);
        self::assertSame(0, $result['delay_ms']);
    }
}
