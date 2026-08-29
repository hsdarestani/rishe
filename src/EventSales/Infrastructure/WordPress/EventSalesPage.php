<?php

declare(strict_types=1);

namespace Rishe\EventSales\Infrastructure\WordPress;

final class EventSalesPage
{
    private const REWRITE_VERSION = '2026082901';

    public function register(): void
    {
        add_action('init', [$this, 'rewrites']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('template_redirect', [$this, 'dispatch'], 0);
    }

    public function rewrites(): void
    {
        add_rewrite_rule('^rishe-event-app/?$', 'index.php?rishe_event_app=1', 'top');
        add_rewrite_rule('^rishe-event-app/manifest\\.webmanifest$', 'index.php?rishe_event_manifest=1', 'top');
        add_rewrite_rule('^rishe-event-app/sw\\.js$', 'index.php?rishe_event_sw=1', 'top');
        if ((string) get_option('rishe_event_rewrite_version', '') !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('rishe_event_rewrite_version', self::REWRITE_VERSION, false);
        }
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array
    {
        $vars[] = 'rishe_event_app';
        $vars[] = 'rishe_event_manifest';
        $vars[] = 'rishe_event_sw';

        return $vars;
    }

    public function dispatch(): void
    {
        $requestPath = $this->requestPath();
        $appPath = $this->homePath('/rishe-event-app/');
        $manifestPath = $this->homePath('/rishe-event-app/manifest.webmanifest');
        $swPath = $this->homePath('/rishe-event-app/sw.js');

        if ((int) get_query_var('rishe_event_manifest') === 1 || $requestPath === $manifestPath) {
            $this->manifest();
        }
        if ((int) get_query_var('rishe_event_sw') === 1 || $requestPath === $swPath) {
            $this->serviceWorker();
        }
        if (
            (int) get_query_var('rishe_event_app') === 1
            || rtrim($requestPath, '/') === rtrim($appPath, '/')
        ) {
            $this->app();
        }
    }

    private function requestPath(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function homePath(string $path): string
    {
        $resolved = wp_parse_url(home_url($path), PHP_URL_PATH);

        return is_string($resolved) && $resolved !== '' ? $resolved : $path;
    }

    private function app(): never
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/rishe-event-app/')));
            exit;
        }
        if (
            !current_user_can('rishe_sell_event')
            && !current_user_can('rishe_manage_sales')
            && !current_user_can('manage_rishe')
        ) {
            wp_die(esc_html__('شما اجازه استفاده از اپ فروش ایونت را ندارید.', 'rishe'), '', ['response' => 403]);
        }
        status_header(200);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('Cache-Control: private, no-cache, must-revalidate');
        $config = [
            'root' => '/rishe/v1/event-sales',
            'restUrl' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => RISHE_VERSION,
            'home' => home_url('/rishe-event-app/'),
            'logout' => wp_logout_url(home_url('/')),
        ];
        ?><!doctype html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#173c2f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>فروش ایونت ریشه</title>
    <link rel="manifest" href="<?php echo esc_url(home_url('/rishe-event-app/manifest.webmanifest')); ?>">
    <link rel="icon" href="<?php echo esc_url(RISHE_URL . 'assets/event-app/icon.svg'); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo esc_url(RISHE_URL . 'assets/event-app/app.css?ver=' . RISHE_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(RISHE_URL . 'assets/event-app/pos-v23.css?ver=' . RISHE_VERSION); ?>">
</head>
<body>
<div class="event-app" id="rishe-event-app">
    <header class="event-app__header">
        <div class="event-app__brand"><span>ر</span><div><strong>دکان ریشه</strong><small>فروش ایونت</small></div></div>
        <div class="event-app__state"><i data-online></i><span data-online-text>در حال بررسی اتصال</span><b data-pending>۰</b></div>
    </header>
    <main>
        <section class="event-dashboard">
            <div><small>ایونت فعال</small><strong data-active-event>—</strong><span data-seller-name>فروشنده</span></div>
            <div class="event-dashboard__stats"><article><small>فروش امروز</small><b data-today-amount>۰ تومان</b></article><article><small>اقلام فروخته‌شده</small><b data-today-items>۰</b></article><article><small>در انتظار سینک</small><b data-dashboard-pending>۰</b></article></div>
        </section>
        <section class="event-screen is-active" data-screen="sale">
            <div class="event-app__event"><label>ایونت فعال<select data-event-select><option value="">انتخاب ایونت</option></select></label><button type="button" data-refresh>↻</button></div>
            <div class="event-app__customer"><input data-customer-name required placeholder="نام مشتری *"><input data-customer-mobile inputmode="tel" placeholder="شماره موبایل (اختیاری)"></div>
            <div class="event-app__search"><input data-search placeholder="جست‌وجوی کالا یا بارکد"><span>⌕</span></div>
            <div class="event-app__products" data-products><div class="event-app__empty">ابتدا ایونت را انتخاب کنید.</div></div>
            <section class="event-cart">
                <header><h2>سبد فروش</h2><span data-cart-count>۰ قلم</span></header>
                <div data-cart><div class="event-app__empty">هنوز کالایی اضافه نشده.</div></div>
                <div class="event-cart__summary">
                    <label><span>جمع کالاها</span><strong data-subtotal>۰ تومان</strong></label>
                    <label><span>تخفیف</span><strong>۰ تومان</strong></label>
                    <label class="is-total"><span>مبلغ نهایی</span><strong data-total>۰ تومان</strong></label>
                    <label><span>مبلغ پرداختی</span><input data-paid type="number" min="0" inputmode="numeric"><em>تومان</em></label>
                    <label><span>روش پرداخت</span><select data-payment><option value="pos">کارت‌خوان</option><option value="cash">نقدی</option><option value="card">کارت‌به‌کارت</option></select></label>
                </div>
                <button class="event-app__submit" type="button" data-submit>ثبت فروش</button>
            </section>
        </section>
        <section class="event-screen" data-screen="queue">
            <div class="event-screen__title"><div><h2>صف آفلاین</h2><p>فروش‌ها پس از اتصال اینترنت خودکار ارسال می‌شوند.</p></div><button type="button" data-sync>همگام‌سازی</button></div>
            <div data-queue></div>
        </section>
    </main>
    <nav class="event-app__nav"><button class="is-active" data-go="sale"><span>＋</span>فروش جدید</button><button data-go="queue"><span>↻</span>صف سینک <b data-nav-pending>۰</b></button></nav>
    <div class="event-app__toast" data-toast hidden></div>
    <div class="event-receipt" data-receipt hidden><div><span class="event-receipt__check">✓</span><h2>فروش با موفقیت ثبت شد</h2><p>شماره رسید</p><strong data-receipt-number></strong><small data-receipt-sync></small><button type="button" data-close-receipt>فروش بعدی</button></div></div>
</div>
<script>window.risheEventApp=<?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo esc_url(RISHE_URL . 'assets/event-app/app.js?ver=' . RISHE_VERSION); ?>" defer></script>
</body>
</html><?php
        exit;
    }

    private function manifest(): never
    {
        status_header(200);
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo wp_json_encode([
            'name' => 'فروش ایونت ریشه',
            'short_name' => 'ریشه',
            'description' => 'ثبت آفلاین فروش ایونت‌های ریشه',
            'start_url' => home_url('/rishe-event-app/'),
            'scope' => home_url('/rishe-event-app/'),
            'display' => 'standalone',
            'background_color' => '#f4f2e9',
            'theme_color' => '#173c2f',
            'dir' => 'rtl',
            'lang' => 'fa',
            'icons' => [[
                'src' => RISHE_URL . 'assets/event-app/icon.svg',
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function serviceWorker(): never
    {
        status_header(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Service-Worker-Allowed: ' . wp_parse_url(home_url('/rishe-event-app/'), PHP_URL_PATH));
        $file = RISHE_PATH . 'assets/event-app/sw.js';
        $contents = is_readable($file) ? (string) file_get_contents($file) : '';
        echo str_replace(
            ['__RISHE_VERSION__', '__RISHE_APP_URL__', '__RISHE_ASSET_URL__'],
            [RISHE_VERSION, home_url('/rishe-event-app/'), RISHE_URL . 'assets/event-app/'],
            $contents
        );
        exit;
    }
}
