<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Domain\BackoffPolicy;

final class BackoffPolicyTest extends TestCase
{
    public function testBackoffDoublesAndCaps(): void
    {
        $policy = new BackoffPolicy(60, 300);

        self::assertSame(60, $policy->delayForAttempt(1));
        self::assertSame(120, $policy->delayForAttempt(2));
        self::assertSame(240, $policy->delayForAttempt(3));
        self::assertSame(300, $policy->delayForAttempt(4));
        self::assertSame(300, $policy->delayForAttempt(10));
    }
}
