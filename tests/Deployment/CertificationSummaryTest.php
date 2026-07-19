<?php

declare(strict_types=1);

namespace Rishe\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use Rishe\Deployment\Domain\CertificationSummary;

final class CertificationSummaryTest extends TestCase
{
    public function testFailureMakesEnvironmentNonCertifiable(): void
    {
        $summary = (new CertificationSummary())->summarize([
            ['code' => 'database', 'status' => 'pass', 'message' => 'ok'],
            ['code' => 'backup', 'status' => 'warn', 'message' => 'old'],
            ['code' => 'https', 'status' => 'fail', 'message' => 'missing'],
        ]);

        self::assertSame('fail', $summary['status']);
        self::assertFalse($summary['certifiable']);
        self::assertSame(['pass' => 1, 'warn' => 1, 'fail' => 1], $summary['counts']);
    }

    public function testWarningsRemainCertifiable(): void
    {
        $summary = (new CertificationSummary())->summarize([
            ['code' => 'backup', 'status' => 'warn', 'message' => 'old'],
        ]);

        self::assertSame('warn', $summary['status']);
        self::assertTrue($summary['certifiable']);
    }
}
