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
            'rishe.php' => ['Plugin Name: ریشه', 'فروش آفلاین ایونت'],
            'src/Infrastructure/WordPress/AdminMenu.php' => ['کارتابل تأیید اسناد', 'فروش ایونت'],
            'src/Infrastructure/WordPress/BusinessAdminPage.php' => ['سیستم عملیاتی کسب‌وکار ریشه', 'مرکز فرمان ریشه'],
            'src/Accounting/Infrastructure/WordPress/AccountingReviewAdminPage.php' => ['کارتابل تأیید اسناد', 'منابع انسانی'],
            'src/EventSales/Infrastructure/WordPress/EventSalesAdminPage.php' => ['ایونت‌های فروش ریشه', 'اپ فروش'],
            'src/EventSales/Infrastructure/WordPress/EventSalesPage.php' => ['فروش ایونت ریشه', 'صف آفلاین'],
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

    public function testAccountingReviewAndOfflineEventSalesAreRegistered(): void
    {
        $root = dirname(__DIR__, 3);
        $plugin = file_get_contents($root . '/src/Plugin.php');
        $migration = file_get_contents(
            $root . '/src/Infrastructure/Database/Migrations/CreateAccountingReviewAndEventSalesTables.php'
        );
        $app = file_get_contents($root . '/assets/event-app/app.js');

        self::assertIsString($plugin);
        self::assertIsString($migration);
        self::assertIsString($app);
        self::assertStringContainsString('AccountingApprovalRestApi', $plugin);
        self::assertStringContainsString('EventSalesRestApi', $plugin);
        self::assertStringContainsString('rishe_voucher_reviews', $migration);
        self::assertStringContainsString('rishe_event_sales', $migration);
        self::assertStringContainsString("indexedDB.open('rishe-event-sales'", $app);
        self::assertStringContainsString("window.addEventListener('online'", $app);
    }

    public function testNewToolsDoNotAddSeparateDailyMenuItems(): void
    {
        $root = dirname(__DIR__, 3);
        $menu = file_get_contents($root . '/src/Infrastructure/WordPress/AdminMenu.php');
        $business = file_get_contents($root . '/src/Infrastructure/WordPress/BusinessAdminPage.php');
        $accounting = file_get_contents(
            $root . '/src/Accounting/Infrastructure/WordPress/AccountingReviewAdminPage.php'
        );
        $events = file_get_contents($root . '/src/EventSales/Infrastructure/WordPress/EventSalesAdminPage.php');

        self::assertIsString($menu);
        self::assertIsString($business);
        self::assertIsString($accounting);
        self::assertIsString($events);
        self::assertStringNotContainsString("'rishe-event-sales' =>", $menu);
        self::assertStringNotContainsString("'rishe-accounting-review' =>", $menu);
        self::assertStringContainsString('renderWorkspaceEntry', $business);
        self::assertStringContainsString("return \$this->isCurrentPage() ? 'rishe-work-finance'", $accounting);
        self::assertStringContainsString("return \$this->isCurrentPage() ? 'rishe-work-sales'", $events);
    }

    public function testPersianReleaseVersionIsConsistent(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 3) . '/rishe.php');
        self::assertIsString($plugin);
        self::assertStringContainsString('Version: 2.1.1', $plugin);
        self::assertStringContainsString("define('RISHE_VERSION', '2.1.1');", $plugin);
        self::assertStringContainsString("define('RISHE_DB_VERSION', '2026080102');", $plugin);
    }
}
