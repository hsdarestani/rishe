<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Domain\ConfigurationPackage;
use Rishe\Operations\Domain\Exception\OperationsDomainException;

final class ConfigurationPackageTest extends TestCase
{
    public function testChecksumIsStableAcrossAssociativeKeyOrder(): void
    {
        $packages = new ConfigurationPackage();
        $first = $packages->build([
            'beta' => ['z' => 2, 'a' => 1],
            'alpha' => 3,
        ], '1.1.0', '2026-07-20T00:00:00Z');
        $second = $packages->build([
            'alpha' => 3,
            'beta' => ['a' => 1, 'z' => 2],
        ], '1.1.0', '2026-07-20T01:00:00Z');

        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame($first['options'], $packages->validate($first));
    }

    public function testTamperedPackageIsRejected(): void
    {
        $packages = new ConfigurationPackage();
        $package = $packages->build(['alpha' => 1], '1.1.0', '2026-07-20T00:00:00Z');
        $package['options']['alpha'] = 2;

        $this->expectException(OperationsDomainException::class);
        $packages->validate($package);
    }
}
