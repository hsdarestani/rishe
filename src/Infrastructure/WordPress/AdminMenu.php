<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Analytics\Infrastructure\WordPress\AnalyticsAdminPage;
use Rishe\Operations\Infrastructure\WordPress\OperationsAdminPage;

final class AdminMenu
{
    private OperationsAdminPage $operations;
    private AnalyticsAdminPage $analytics;

    public function __construct(?OperationsAdminPage $operations = null, ?AnalyticsAdminPage $analytics = null)
    {
        $this->operations = $operations ?? new OperationsAdminPage();
        $this->analytics = $analytics ?? new AnalyticsAdminPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        $this->operations->register();
        $this->analytics->register();
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Rishe ERP', 'rishe'),
            __('Rishe ERP', 'rishe'),
            'rishe_manage_operations',
            'rishe',
            [$this->operations, 'render'],
            'dashicons-database-view',
            56
        );
        add_submenu_page(
            'rishe',
            __('Operations', 'rishe'),
            __('Operations', 'rishe'),
            'rishe_manage_operations',
            'rishe-operations',
            [$this->operations, 'render']
        );
        add_submenu_page(
            'rishe',
            __('Analytics', 'rishe'),
            __('Analytics', 'rishe'),
            'rishe_view_reports',
            'rishe-analytics',
            [$this->analytics, 'render']
        );
    }
}
