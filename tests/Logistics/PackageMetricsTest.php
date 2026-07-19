<?php

declare(strict_types=1);

namespace Rishe\Tests\Logistics;

use PHPUnit\Framework\TestCase;
use Rishe\Logistics\Domain\PackageMetrics;

final class PackageMetricsTest extends TestCase
{
    public function testCalculatesPhysicalAndVolumetricWeight(): void
    {
        $metrics = new PackageMetrics(2000, 500, 400, 300, 2);

        self::assertSame(4000, $metrics->totalWeightGrams());
        self::assertSame(24000, $metrics->volumetricWeightGrams());
    }
}
