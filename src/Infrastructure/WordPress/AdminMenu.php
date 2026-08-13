<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Accounting\Infrastructure\WordPress\AccountingReviewAdminPage;
use Rishe\Analytics\Infrastructure\WordPress\AnalyticsAdminPage;
use Rishe\EventSales\Infrastructure\WordPress\EventSalesAdminPage;
use Rishe\Operations\Infrastructure\WordPress\OperationsAdminPage;
use Rishe\Sales\Infrastructure\WordPress\SalesInsightsAdminPage;

final class AdminMenu
{
    private OperationsAdminPage $operations;
    private AnalyticsAdminPage $analytics;
    private ErpAdminPage $erp;
    private BusinessAdminPage $business;
    private AccountingReviewAdminPage $accountingReview;
    private EventSalesAdminPage $eventSales;
    private SalesInsightsAdminPage $salesInsights;

    public function __construct(
        ?OperationsAdminPage $operations = null,
        ?AnalyticsAdminPage $analytics = null,
        ?ErpAdminPage $erp = null,
        ?BusinessAdminPage $business = null,
        ?AccountingReviewAdminPage $accountingReview = null,
        ?EventSalesAdminPage $eventSales = null,
        ?SalesInsightsAdminPage $salesInsights = null
    ) {
        $this->operations = $operations ?? new OperationsAdminPage();
        $this->analytics = $analytics ?? new AnalyticsAdminPage();
        $this->erp = $erp ?? new ErpAdminPage();
        $this->business = $business ?? new BusinessAdminPage();
        $this->accountingReview = $accountingReview ?? new AccountingReviewAdminPage();
        $this->eventSales = $eventSales ?? new EventSalesAdminPage();
        $this->salesInsights = $salesInsights ?? new SalesInsightsAdminPage();
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
        $this->salesInsights->register();
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('ریشه', 'rishe'),
            __('ریشه', 'rishe'),
            'rishe_access_app',
            'rishe',
            [$this->business, 'render'],
            'dashicons-palmtree',
            56
        );

        if ($this->canSeeDashboard()) {
            add_submenu_page(
                'rishe',
                __('مرکز فرمان ریشه', 'rishe'),
                __('مرکز فرمان', 'rishe'),
                'rishe_access_app',
                'rishe',
                [$this->business, 'render']
            );
        }

        $primary = [
            'rishe-work-inventory' => ['انبار و بسته‌بندی', 'rishe_manage_inventory'],
            'rishe-work-sales' => ['فروش و بازاریابی', 'rishe_manage_sales'],
            'rishe-work-procurement' => ['بازرگانی و تأمین', 'rishe_manage_procurement'],
            'rishe-work-finance' => ['مالی و حسابداری', 'rishe_manage_accounting'],
            'rishe-work-logistics' => ['لجستیک', 'rishe_manage_logistics'],
            'rishe-work-b2b' => ['فروش B2B', 'rishe_manage_b2b'],
        ];
        foreach ($primary as $slug => [$title, $capability]) {
            if (!$this->canSeeSection($capability)) {
                continue;
            }

            add_submenu_page(
                'rishe',
                $title,
                $title,
                'rishe_access_app',
                $slug,
                [$this->business, 'render']
            );
        }

        // ابزارهای روزمره از داخل فضای کاری مرتبط باز می‌شوند و منوی جدا ندارند.
        add_submenu_page(
            null,
            __('کارتابل تأیید اسناد', 'rishe'),
            __('کارتابل تأیید اسناد', 'rishe'),
            'rishe_manage_accounting',
            AccountingReviewAdminPage::SLUG,
            [$this->accountingReview, 'render']
        );
        add_submenu_page(
            null,
            __('فروش ایونت', 'rishe'),
            __('فروش ایونت', 'rishe'),
            'rishe_manage_sales',
            EventSalesAdminPage::SLUG,
            [$this->eventSales, 'render']
        );
        add_submenu_page(
            null,
            __('گزارش فروش و مشتریان', 'rishe'),
            __('گزارش فروش و مشتریان', 'rishe'),
            'rishe_view_sales_dashboard',
            SalesInsightsAdminPage::SLUG,
            [$this->salesInsights, 'render']
        );

        if ($this->canSeeAnalytics()) {
            add_submenu_page(
                'rishe',
                __('گزارش‌های مدیریتی', 'rishe'),
                __('گزارش‌های مدیریتی', 'rishe'),
                'rishe_view_reports',
                'rishe-analytics',
                [$this->analytics, 'render']
            );
        }

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

    private function canSeeDashboard(): bool
    {
        if (current_user_can('manage_rishe') || current_user_can('rishe_view_all_sections')) {
            return true;
        }

        return !current_user_can('rishe_restricted_admin');
    }

    private function canSeeSection(string $capability): bool
    {
        return current_user_can('manage_rishe')
            || current_user_can('rishe_view_all_sections')
            || current_user_can($capability);
    }

    private function canSeeAnalytics(): bool
    {
        return current_user_can('manage_rishe')
            || current_user_can('rishe_view_all_sections')
            || current_user_can('rishe_manage_analytics');
    }
}
