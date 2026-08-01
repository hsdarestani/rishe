<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Analytics\Infrastructure\WordPress\AnalyticsAdminPage;
use Rishe\Operations\Infrastructure\WordPress\OperationsAdminPage;

final class AdminMenu
{
    private OperationsAdminPage $operations;
    private AnalyticsAdminPage $analytics;
    private ErpAdminPage $erp;
    private BusinessAdminPage $business;

    public function __construct(
        ?OperationsAdminPage $operations = null,
        ?AnalyticsAdminPage $analytics = null,
        ?ErpAdminPage $erp = null,
        ?BusinessAdminPage $business = null
    ) {
        $this->operations = $operations ?? new OperationsAdminPage();
        $this->analytics = $analytics ?? new AnalyticsAdminPage();
        $this->erp = $erp ?? new ErpAdminPage();
        $this->business = $business ?? new BusinessAdminPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        $this->operations->register();
        $this->analytics->register();
        $this->erp->register();
        $this->business->register();
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('ریشه', 'rishe'),
            __('ریشه', 'rishe'),
            'manage_rishe',
            'rishe',
            [$this->business, 'render'],
            'dashicons-palmtree',
            56
        );

        add_submenu_page(
            'rishe',
            __('مرکز فرمان ریشه', 'rishe'),
            __('مرکز فرمان', 'rishe'),
            'manage_rishe',
            'rishe',
            [$this->business, 'render']
        );

        $primary = [
            'rishe-work-inventory' => ['انبار و بسته‌بندی', 'rishe_manage_inventory'],
            'rishe-work-sales' => ['فروش و بازاریابی', 'rishe_manage_sales'],
            'rishe-work-procurement' => ['بازرگانی و تأمین', 'rishe_manage_procurement'],
            'rishe-work-finance' => ['مالی و حسابداری', 'rishe_manage_accounting'],
            'rishe-work-logistics' => ['لجستیک', 'rishe_manage_logistics'],
            'rishe-work-b2b' => ['فروش B2B', 'rishe_manage_b2b'],
        ];
        foreach ($primary as $slug => [$title, $capability]) {
            add_submenu_page(
                'rishe',
                $title,
                $title,
                $capability,
                $slug,
                [$this->business, 'render']
            );
        }

        add_submenu_page(
            'rishe',
            __('گزارش‌های مدیریتی', 'rishe'),
            __('گزارش‌های مدیریتی', 'rishe'),
            'rishe_view_reports',
            'rishe-analytics',
            [$this->analytics, 'render']
        );

        // صفحات تخصصی حفظ شده‌اند اما از منوی روزمره حذف شده‌اند؛
        // کاربر فقط وقتی از داخل یک فلو روی «تنظیمات پیشرفته» می‌زند وارد آن‌ها می‌شود.
        foreach (ErpAdminPage::modules() as $slug => $module) {
            add_submenu_page(
                null,
                $module['title'],
                $module['title'],
                $module['capability'],
                'rishe-' . $slug,
                [$this->erp, 'render']
            );
        }

        add_submenu_page(
            null,
            __('مرکز عملیات', 'rishe'),
            __('مرکز عملیات', 'rishe'),
            'rishe_manage_operations',
            'rishe-operations',
            [$this->operations, 'render']
        );
    }
}
