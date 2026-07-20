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

    public function __construct(
        ?OperationsAdminPage $operations = null,
        ?AnalyticsAdminPage $analytics = null,
        ?ErpAdminPage $erp = null
    ) {
        $this->operations = $operations ?? new OperationsAdminPage();
        $this->analytics = $analytics ?? new AnalyticsAdminPage();
        $this->erp = $erp ?? new ErpAdminPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        $this->operations->register();
        $this->analytics->register();
        $this->erp->register();
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Rishe ERP', 'rishe'),
            __('Rishe ERP', 'rishe'),
            'manage_rishe',
            'rishe',
            [$this->operations, 'render'],
            'dashicons-database-view',
            56
        );

        add_submenu_page(
            'rishe',
            __('Operations', 'rishe'),
            __('مرکز عملیات', 'rishe'),
            'rishe_manage_operations',
            'rishe-operations',
            [$this->operations, 'render']
        );

        foreach (ErpAdminPage::modules() as $slug => $module) {
            add_submenu_page(
                'rishe',
                $module['title'],
                $module['title'],
                $module['capability'],
                'rishe-' . $slug,
                [$this->erp, 'render']
            );
        }

        add_submenu_page(
            'rishe',
            __('Analytics', 'rishe'),
            __('تحلیل و داشبورد', 'rishe'),
            'rishe_view_reports',
            'rishe-analytics',
            [$this->analytics, 'render']
        );
    }
}
