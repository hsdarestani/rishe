<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Operations\Infrastructure\WordPress\OperationsAdminPage;

final class AdminMenu
{
    private OperationsAdminPage $operations;

    public function __construct(?OperationsAdminPage $operations = null)
    {
        $this->operations = $operations ?? new OperationsAdminPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        $this->operations->register();
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
    }
}
