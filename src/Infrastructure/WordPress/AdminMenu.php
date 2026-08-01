<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Accounting\Infrastructure\WordPress\AccountingReviewAdminPage;
use Rishe\Analytics\Infrastructure\WordPress\AnalyticsAdminPage;
use Rishe\EventSales\Infrastructure\WordPress\EventSalesAdminPage;
use Rishe\Operations\Infrastructure\WordPress\OperationsAdminPage;

final class AdminMenu
{
    private OperationsAdminPage $operations;
    private AnalyticsAdminPage $analytics;
    private ErpAdminPage $erp;
    private BusinessAdminPage $business;
    private AccountingReviewAdminPage $accountingReview;
    private EventSalesAdminPage $eventSales;

    public function __construct(
        ?OperationsAdminPage $operations = null,
        ?AnalyticsAdminPage $analytics = null,
        ?ErpAdminPage $erp = null,
        ?BusinessAdminPage $business = null,
        ?AccountingReviewAdminPage $accountingReview = null,
        ?EventSalesAdminPage $eventSales = null
    ) {
        $this->operations = $operations ?? new OperationsAdminPage();
        $this->analytics = $analytics ?? new AnalyticsAdminPage();
        $this->erp = $erp ?? new ErpAdminPage();
        $this->business = $business ?? new BusinessAdminPage();
        $this->accountingReview = $accountingReview ?? new AccountingReviewAdminPage();
        $this->eventSales = $eventSales ?? new EventSalesAdminPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        $this->operations->register();
        $this->analytics->register();
        $this->erp->register();
        $this->business->register();
        $this->accountingReview->register();
        $this->eventSales->register();
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
            'rishe-event-sales' => ['فروش ایونت', 'rishe_manage_sales'],
            'rishe-work-procurement' => ['بازرگانی و تأمین', 'rishe_manage_procurement'],
            'rishe-work-finance' => ['مالی و حسابداری', 'rishe_manage_accounting'],
            'rishe-accounting-review' => ['کارتابل تأیید اسناد', 'rishe_manage_accounting'],
            'rishe-work-logistics' => ['لجستیک', 'rishe_manage_logistics'],
            'rishe-work-b2b' => ['فروش B2B', 'rishe_manage_b2b'],
        ];
        foreach ($primary as $slug => [$title, $capability]) {
            $callback = match ($slug) {
                EventSalesAdminPage::SLUG => [$this->eventSales, 'render'],
                AccountingReviewAdminPage::SLUG => [$this->accountingReview, 'render'],
                default => [$this->business, 'render'],
            };
            add_submenu_page('rishe', $title, $title, $capability, $slug, $callback);
        }

        add_submenu_page(
            'rishe',
            __('گزارش‌های مدیریتی', 'rishe'),
            __('گزارش‌های مدیریتی', 'rishe'),
            'rishe_view_reports',
            'rishe-analytics',
            [$this->analytics, 'render']
        );

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
