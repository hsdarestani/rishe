<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Rishe ERP', 'rishe'),
            __('Rishe ERP', 'rishe'),
            'manage_rishe',
            'rishe',
            [$this, 'renderDashboard'],
            'dashicons-database',
            56
        );
    }

    public function renderDashboard(): void
    {
        if (!current_user_can('manage_rishe')) {
            wp_die(esc_html__('You do not have permission to access Rishe ERP.', 'rishe'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Rishe ERP', 'rishe') . '</h1>';
        echo '<p>' . esc_html__('Foundation is active. Business modules will be added as isolated bounded contexts.', 'rishe') . '</p>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        echo '<tr><th>' . esc_html__('Plugin version', 'rishe') . '</th><td>' . esc_html(RISHE_VERSION) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Database version', 'rishe') . '</th><td>' . esc_html((string) get_option('rishe_db_version', 'not installed')) . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
    }
}
