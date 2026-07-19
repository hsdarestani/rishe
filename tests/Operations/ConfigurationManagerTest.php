<?php

declare(strict_types=1);

namespace Rishe\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Rishe\Operations\Application\ConfigurationManager;
use Rishe\Operations\Domain\ConfigurationPackage;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Tests\Operations\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Operations\Fakes\InMemoryConfigurationStore;

final class ConfigurationManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('RISHE_VERSION')) {
            define('RISHE_VERSION', '1.1.0');
        }
    }

    public function testPreviewThenApplyRequiresSameChecksum(): void
    {
        $store = new InMemoryConfigurationStore(['rishe_system_user_id' => 1]);
        $manager = new ConfigurationManager($store, new ConfigurationPackage(), new InMemoryAuditRecorder());
        $package = (new ConfigurationPackage())->build(
            ['rishe_system_user_id' => 7],
            '1.1.0',
            '2026-07-20T00:00:00Z'
        );

        $preview = $manager->preview($package);
        $result = $manager->apply($package, (string) $preview['checksum'], 9);

        self::assertSame(1, $preview['change_count']);
        self::assertTrue($result['applied']);
        self::assertSame(7, $store->values['rishe_system_user_id']);
    }

    public function testSecretOrUnknownOptionCannotBeImported(): void
    {
        $manager = new ConfigurationManager(
            new InMemoryConfigurationStore(),
            new ConfigurationPackage(),
            new InMemoryAuditRecorder()
        );
        $package = (new ConfigurationPackage())->build(
            ['rishe_woocommerce_webhook_secret' => 'secret'],
            '1.1.0',
            '2026-07-20T00:00:00Z'
        );

        $this->expectException(OperationsDomainException::class);
        $manager->preview($package);
    }
}
