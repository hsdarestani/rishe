<?php

declare(strict_types=1);

namespace Rishe\Tests\Infrastructure\WordPress;

use PHPUnit\Framework\TestCase;

final class PersianLocalizationTest extends TestCase
{
    public function testAdminPagesUsePersianPrimaryLabels(): void
    {
        $root = dirname(__DIR__, 3);
        $expectations = [
            'rishe.php' => ['Plugin Name: ریشه', 'سیستم عملیاتی'],
            'src/Infrastructure/WordPress/AdminMenu.php' => ['مرکز فرمان', 'انبار و بسته‌بندی', 'فروش و بازاریابی'],
            'src/Infrastructure/WordPress/BusinessAdminPage.php' => ['سیستم عملیاتی کسب‌وکار ریشه', 'مرکز فرمان ریشه'],
            'src/Infrastructure/WordPress/ErpAdminPage.php' => ['محیط کاری سامانه ریشه', 'بخش‌های ماژول'],
            'src/Operations/Infrastructure/WordPress/OperationsAdminPage.php' => ['مرکز کنترل عملیات', 'کارهای پس‌زمینه'],
            'src/Analytics/Infrastructure/WordPress/AnalyticsAdminPage.php' => ['هوش مدیریتی', 'تحلیل‌های سامانه ریشه'],
            'src/WooCommerce/Infrastructure/WordPress/WooCommerceSyncAdminPage.php' => ['اتصال کامل ووکامرس', 'انبار مرجع ریشه'],
        ];

        foreach ($expectations as $path => $phrases) {
            $contents = file_get_contents($root . '/' . $path);
            self::assertIsString($contents, $path);
            foreach ($phrases as $phrase) {
                self::assertStringContainsString($phrase, $contents, $path . ': ' . $phrase);
            }
        }
    }

    public function testDynamicAdminContentHasPersianTranslationLayer(): void
    {
        $root = dirname(__DIR__, 3);
        $contents = file_get_contents($root . '/assets/admin/persian.js');
        self::assertIsString($contents);
        self::assertStringContainsString("'pending': 'در انتظار'", $contents);
        self::assertStringContainsString("'tax.submit': 'ارسال صورتحساب به سامانه مؤدیان'", $contents);
        self::assertStringContainsString('MutationObserver', $contents);
        self::assertStringContainsString('localizeObject', $contents);
    }

    public function testBusinessDialogAndWarehouseRecoveryAreRegistered(): void
    {
        $root = dirname(__DIR__, 3);
        $plugin = file_get_contents($root . '/src/Plugin.php');
        $dialog = file_get_contents($root . '/src/Infrastructure/WordPress/BusinessDialogCompatibility.php');
        $warehouse = file_get_contents($root . '/src/Infrastructure/WordPress/DefaultWarehouseProvisioner.php');

        self::assertIsString($plugin);
        self::assertIsString($dialog);
        self::assertIsString($warehouse);
        self::assertStringContainsString('BusinessDialogCompatibility', $plugin);
        self::assertStringContainsString('DefaultWarehouseProvisioner', $plugin);
        self::assertStringContainsString('repairDialogFrame', $dialog);
        self::assertStringContainsString('انبار مرکزی', $warehouse);
    }

    public function testPersianReleaseVersionIsConsistent(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 3) . '/rishe.php');
        self::assertIsString($plugin);
        self::assertStringContainsString('Version: 2.0.1', $plugin);
        self::assertStringContainsString("define('RISHE_VERSION', '2.0.1');", $plugin);
    }
}
