<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Domain\DiagnosticSummary;

final class DiagnosticSummaryTest extends TestCase
{
    public function testHighestSeverityDeterminesSummary(): void
    {
        $report = (new DiagnosticSummary())->summarize([
            ['key' => 'a', 'status' => 'ok'],
            ['key' => 'b', 'status' => 'warning'],
            ['key' => 'c', 'status' => 'critical'],
        ]);

        self::assertSame('critical', $report['status']);
        self::assertSame(1, $report['counts']['critical']);
    }
}
