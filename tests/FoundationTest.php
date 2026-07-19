<?php

declare(strict_types=1);

namespace Rishe\Tests;

use PHPUnit\Framework\TestCase;
use Rishe\Infrastructure\Database\Migration;
use Rishe\Infrastructure\Database\Migrator;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Plugin;
use Rishe\Shared\Audit\AuditLogger;

final class FoundationTest extends TestCase
{
    public function testFoundationClassesAreAutoloadable(): void
    {
        self::assertTrue(class_exists(Plugin::class));
        self::assertTrue(interface_exists(Migration::class));
        self::assertTrue(class_exists(Migrator::class));
        self::assertTrue(class_exists(TransactionManager::class));
        self::assertTrue(class_exists(AuditLogger::class));
    }
}
