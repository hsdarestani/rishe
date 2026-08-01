<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

final class EventSalesAdminPage
{
    public const SLUG = 'rishe-event-sales';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'rishe_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style(
            'rishe-event-sales-admin',
            RISHE_URL . 'assets/admin/event-sales.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-event-sales-admin',
            RISHE_URL . 'assets/admin/event-sales.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-event-sales-admin', 'risheEventSalesAdmin', [
            'root' => '/rishe/v1/event-sales',
            'nonce' => wp_create_nonce('wp_rest'),
            'appUrl' => home_url('/rishe-event-app/'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('rishe_manage_sales') && !current_user_can('manage_rishe')) {
            wp_die(esc_html__('شما اجازه مدیریت فروش ایونت را ندارید.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-events" id="rishe-event-sales-admin" dir="rtl" lang="fa">
            <header class="rishe-events__hero">
                <div>
                    <p>فروش حضوری و بازارچه</p>
                    <h1>ایونت‌های فروش ریشه</h1>
                    <span>ایونت را تعریف کنید، انبار و فروشنده‌ها را مشخص کنید؛ ثبت فروش از اپ موبایل انجام می‌شود.</span>
                </div>
                <div class="rishe-events__hero-actions">
                    <a class="button button-secondary button-hero" href="<?php echo esc_url(home_url('/rishe-event-app/')); ?>" target="_blank">بازکردن اپ فروش</a>
                    <button class="button button-primary button-hero" type="button" data-new-event>ایونت جدید</button>
                </div>
            </header>
            <div class="rishe-events__notice" data-notice hidden></div>
            <section class="rishe-events__stats" data-stats></section>
            <main class="rishe-events__grid">
                <section class="rishe-events__panel">
                    <header><h2>ایونت‌ها</h2><p>پیش‌نویس، فعال و بسته‌شده</p></header>
                    <div data-events><div class="rishe-events__loading"><span class="spinner is-active"></span> در حال دریافت…</div></div>
                </section>
                <section class="rishe-events__panel">
                    <header><h2>فروش‌های اخیر</h2><p>فروش‌های سینک‌شده از موبایل</p></header>
                    <div data-sales><div class="rishe-events__loading"><span class="spinner is-active"></span> در حال دریافت…</div></div>
                </section>
            </main>
            <dialog class="rishe-events__dialog" data-dialog>
                <div class="rishe-events__dialog-frame">
                    <header><h2 data-dialog-title></h2><button type="button" data-close>×</button></header>
                    <div data-dialog-body></div>
                </div>
            </dialog>
        </div>
        <?php
    }
}
