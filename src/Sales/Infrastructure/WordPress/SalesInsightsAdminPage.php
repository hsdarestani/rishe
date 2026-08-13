<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure\WordPress;

final class SalesInsightsAdminPage
{
    public const SLUG = 'rishe-sales-insights';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== self::SLUG) {
            return;
        }

        wp_enqueue_style(
            'rishe-sales-insights',
            RISHE_URL . 'assets/admin/sales-insights.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-sales-insights',
            RISHE_URL . 'assets/admin/sales-insights.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-sales-insights', 'risheSalesInsights', [
            'nonce' => wp_create_nonce('wp_rest'),
            'root' => '/rishe/v1/sales-intelligence',
            'today' => gmdate('Y-m-d'),
            'monthStart' => gmdate('Y-m-01'),
            'month' => gmdate('Y-m'),
            'canManageTarget' => current_user_can('rishe_manage_sales_targets')
                || current_user_can('manage_rishe'),
            'financeUrl' => admin_url('admin.php?page=rishe-work-finance'),
            'salesUrl' => admin_url('admin.php?page=rishe-work-sales'),
        ]);
    }

    public function render(): void
    {
        if (
            !current_user_can('rishe_view_sales_dashboard')
            && !current_user_can('rishe_view_customers')
            && !current_user_can('manage_rishe')
        ) {
            wp_die(esc_html__('شما اجازه مشاهده گزارش فروش و مشتریان را ندارید.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-insights" id="rishe-sales-insights" dir="rtl" lang="fa">
            <header class="rishe-insights__header">
                <div>
                    <p>هوش فروش ریشه</p>
                    <h1>گزارش فروش و دیتابیس مشتریان</h1>
                    <span>فروش همه کانال‌ها، تارگت ماهانه، انحراف، جزئیات سفارش و رفتار مشتری.</span>
                </div>
                <div class="rishe-insights__header-actions">
                    <span>نسخه <?php echo esc_html(RISHE_VERSION); ?></span>
                    <button type="button" class="button" data-refresh>تازه‌سازی</button>
                </div>
            </header>

            <nav class="rishe-insights__tabs" aria-label="گزارش فروش">
                <?php if (current_user_can('rishe_view_sales_dashboard') || current_user_can('manage_rishe')) : ?>
                    <button type="button" class="is-active" data-tab="sales">گزارش فروش</button>
                <?php endif; ?>
                <?php if (current_user_can('rishe_view_customers') || current_user_can('manage_rishe')) : ?>
                    <button type="button" data-tab="customers">دیتابیس مشتریان</button>
                <?php endif; ?>
            </nav>

            <div class="rishe-insights__notice" data-notice hidden></div>
            <main data-content>
                <div class="rishe-insights__loading"><span class="spinner is-active"></span>در حال دریافت اطلاعات…</div>
            </main>
        </div>
        <?php
    }
}
