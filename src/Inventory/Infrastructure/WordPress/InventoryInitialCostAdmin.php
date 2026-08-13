<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure\WordPress;

final class InventoryInitialCostAdmin
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'assets'], 30);
    }

    public function assets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== 'rishe-work-inventory' || !current_user_can('rishe_manage_inventory')) {
            return;
        }

        wp_enqueue_style(
            'rishe-initial-cost',
            RISHE_URL . 'assets/admin/initial-cost.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-initial-cost',
            RISHE_URL . 'assets/admin/initial-cost.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-initial-cost', 'risheInitialCost', [
            'nonce' => wp_create_nonce('wp_rest'),
            'stockPath' => '/rishe/v1/inventory/stock',
            'savePath' => '/rishe/v1/inventory/initial-costs',
        ]);
    }
}
