<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class PersianAdminLocalization
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        unset($hook);

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== 'rishe' && !str_starts_with($page, 'rishe-')) {
            return;
        }

        wp_enqueue_script(
            'rishe-persian-admin',
            RISHE_URL . 'assets/admin/persian.js',
            [],
            RISHE_VERSION,
            true
        );
    }
}
