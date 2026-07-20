<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure\WordPress;

final class AnalyticsAdminPage
{
    /** @var list<string> */
    private const PAGE_HOOKS = [
        'rishe-erp_page_rishe-analytics',
        'rishe_page_rishe-analytics',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, self::PAGE_HOOKS, true)) {
            return;
        }
        wp_enqueue_style('rishe-analytics-admin', RISHE_URL . 'assets/admin/analytics.css', [], RISHE_VERSION);
        wp_enqueue_script('rishe-analytics-admin', RISHE_URL . 'assets/admin/analytics.js', ['wp-api-fetch'], RISHE_VERSION, true);
        wp_localize_script('rishe-analytics-admin', 'risheAnalytics', [
            'root' => '/rishe/v1/analytics',
            'nonce' => wp_create_nonce('wp_rest'),
            'today' => gmdate('Y-m-d'),
            'monthStart' => gmdate('Y-m-01'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('rishe_view_reports')) {
            wp_die(esc_html__('You do not have permission to view Rishe analytics.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-analytics" id="rishe-analytics-app">
            <header class="rishe-analytics__hero">
                <div>
                    <p class="rishe-analytics__eyebrow"><?php echo esc_html__('Executive intelligence', 'rishe'); ?></p>
                    <h1><?php echo esc_html__('Rishe Analytics', 'rishe'); ?></h1>
                    <p><?php echo esc_html__('Event-driven KPIs, targets, attribution, inventory snapshots, and actionable alerts.', 'rishe'); ?></p>
                </div>
                <button type="button" class="button button-primary" data-action="refresh"><?php echo esc_html__('Refresh', 'rishe'); ?></button>
            </header>

            <form class="rishe-analytics__filters" data-role="filters">
                <label><span><?php echo esc_html__('From', 'rishe'); ?></span><input type="date" name="from" value="<?php echo esc_attr(gmdate('Y-m-01')); ?>"></label>
                <label><span><?php echo esc_html__('To', 'rishe'); ?></span><input type="date" name="to" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>"></label>
                <label><span><?php echo esc_html__('Channel', 'rishe'); ?></span><input type="text" name="sales_channel" placeholder="website / pos"></label>
                <label><span><?php echo esc_html__('Product line', 'rishe'); ?></span><input type="text" name="product_line"></label>
                <button type="submit" class="button"><?php echo esc_html__('Apply', 'rishe'); ?></button>
            </form>

            <div class="notice notice-error inline hidden" data-role="error"><p></p></div>
            <nav class="rishe-analytics__tabs" aria-label="Analytics dashboards">
                <button type="button" class="is-active" data-dashboard="executive"><?php echo esc_html__('Executive', 'rishe'); ?></button>
                <button type="button" data-dashboard="sales"><?php echo esc_html__('Sales', 'rishe'); ?></button>
                <button type="button" data-dashboard="inventory"><?php echo esc_html__('Inventory', 'rishe'); ?></button>
                <button type="button" data-dashboard="finance"><?php echo esc_html__('Finance', 'rishe'); ?></button>
                <button type="button" data-dashboard="customers"><?php echo esc_html__('Customers', 'rishe'); ?></button>
            </nav>

            <section class="rishe-analytics__cards" data-role="cards" aria-live="polite"></section>
            <div class="rishe-analytics__grid">
                <section class="rishe-analytics__panel rishe-analytics__panel--wide">
                    <div class="rishe-analytics__panel-head"><h2 data-role="report-title"><?php echo esc_html__('Executive overview', 'rishe'); ?></h2></div>
                    <div data-role="report"></div>
                </section>
                <section class="rishe-analytics__panel">
                    <div class="rishe-analytics__panel-head"><h2><?php echo esc_html__('Targets', 'rishe'); ?></h2></div>
                    <div data-role="targets"></div>
                </section>
                <section class="rishe-analytics__panel">
                    <div class="rishe-analytics__panel-head"><h2><?php echo esc_html__('Executive alerts', 'rishe'); ?></h2></div>
                    <div data-role="alerts"></div>
                </section>
            </div>
        </div>
        <?php
    }
}
