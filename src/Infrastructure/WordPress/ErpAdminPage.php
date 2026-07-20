<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class ErpAdminPage
{
    /** @var array<string, array{title: string, capability: string, icon: string, description: string}> */
    private const MODULES = [
        'accounting' => [
            'title' => 'حسابداری',
            'capability' => 'rishe_manage_accounting',
            'icon' => 'dashicons-chart-area',
            'description' => 'کدینگ حساب‌ها، اسناد حسابداری و تراز آزمایشی',
        ],
        'inventory' => [
            'title' => 'انبار',
            'capability' => 'rishe_manage_inventory',
            'icon' => 'dashicons-archive',
            'description' => 'کالا، انبار، بچ، رسید، رزرو، انتقال و کاردکس',
        ],
        'manufacturing' => [
            'title' => 'تولید',
            'capability' => 'rishe_manage_manufacturing',
            'icon' => 'dashicons-hammer',
            'description' => 'فرمول ساخت، مواد اولیه، ضایعات و دستور تولید',
        ],
        'sales' => [
            'title' => 'فروش و CRM',
            'capability' => 'rishe_manage_sales',
            'icon' => 'dashicons-cart',
            'description' => 'مشتری، قیمت‌گذاری، پروموشن، سفارش و پرداخت',
        ],
        'treasury' => [
            'title' => 'خزانه‌داری',
            'capability' => 'rishe_manage_treasury',
            'icon' => 'dashicons-money-alt',
            'description' => 'حساب‌ها، درگاه‌ها، لینک پرداخت، تراکنش و تسویه',
        ],
        'procurement' => [
            'title' => 'خرید و تأمین',
            'capability' => 'rishe_manage_procurement',
            'icon' => 'dashicons-store',
            'description' => 'تأمین‌کننده، سفارش خرید، رسید و پرداخت',
        ],
        'b2b' => [
            'title' => 'B2B و امانی',
            'capability' => 'rishe_manage_b2b',
            'icon' => 'dashicons-groups',
            'description' => 'حساب عامل، ارسال امانی، گزارش فروش، مرجوعی و تسویه',
        ],
        'logistics' => [
            'title' => 'لجستیک',
            'capability' => 'rishe_manage_logistics',
            'icon' => 'dashicons-location-alt',
            'description' => 'شرکت حمل، مرسوله، استعلام، رزرو، رهگیری و هزینه',
        ],
        'tax' => [
            'title' => 'سامانه مؤدیان',
            'capability' => 'rishe_manage_tax',
            'icon' => 'dashicons-media-spreadsheet',
            'description' => 'پروفایل مالیاتی، نگاشت کالا و صورتحساب رسمی',
        ],
        'settings' => [
            'title' => 'تنظیمات',
            'capability' => 'rishe_manage_settings',
            'icon' => 'dashicons-admin-generic',
            'description' => 'سلامت محیط، اتصال‌ها، پیکربندی و اطلاعات نسخه',
        ],
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        $module = $this->moduleFromHook($hook);
        if ($module === null) {
            return;
        }

        wp_enqueue_style(
            'rishe-erp-admin',
            RISHE_URL . 'assets/admin/erp.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-erp-admin',
            RISHE_URL . 'assets/admin/erp.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-erp-admin', 'risheAdmin', [
            'module' => $module,
            'root' => '/rishe/v1',
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => RISHE_VERSION,
            'databaseVersion' => (string) get_option('rishe_db_version', ''),
            'siteUrl' => home_url('/'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'locale' => get_user_locale(),
            'currency' => 'IRR',
        ]);
    }

    public function render(): void
    {
        $module = $this->currentModule();
        $definition = self::MODULES[$module] ?? null;
        if ($definition === null) {
            wp_die(esc_html__('Unknown Rishe ERP module.', 'rishe'));
        }
        if (!current_user_can($definition['capability']) && !current_user_can('manage_rishe')) {
            wp_die(esc_html__('You do not have permission to access this Rishe ERP module.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-admin" id="rishe-admin-app" dir="rtl">
            <header class="rishe-admin__hero">
                <div class="rishe-admin__hero-copy">
                    <span class="dashicons <?php echo esc_attr($definition['icon']); ?>" aria-hidden="true"></span>
                    <div>
                        <p class="rishe-admin__eyebrow"><?php echo esc_html__('Rishe ERP workspace', 'rishe'); ?></p>
                        <h1><?php echo esc_html($definition['title']); ?></h1>
                        <p><?php echo esc_html($definition['description']); ?></p>
                    </div>
                </div>
                <div class="rishe-admin__hero-actions">
                    <span class="rishe-admin__version">v<?php echo esc_html(RISHE_VERSION); ?></span>
                    <button type="button" class="button" data-rishe-command="help">
                        <?php echo esc_html__('راهنما', 'rishe'); ?>
                    </button>
                    <button type="button" class="button button-primary" data-rishe-command="refresh">
                        <?php echo esc_html__('تازه‌سازی', 'rishe'); ?>
                    </button>
                </div>
            </header>

            <div class="notice notice-error inline hidden" data-rishe-role="error"><p></p></div>
            <div class="notice notice-success inline hidden" data-rishe-role="success"><p></p></div>

            <div class="rishe-admin__shell">
                <nav class="rishe-admin__tabs" data-rishe-role="tabs" aria-label="<?php echo esc_attr__('Module sections', 'rishe'); ?>"></nav>
                <main class="rishe-admin__content" data-rishe-role="content">
                    <div class="rishe-admin__loading">
                        <span class="spinner is-active"></span>
                        <span><?php echo esc_html__('در حال آماده‌سازی رابط کاربری…', 'rishe'); ?></span>
                    </div>
                </main>
            </div>

            <dialog class="rishe-admin__dialog" data-rishe-role="dialog">
                <form method="dialog" class="rishe-admin__dialog-frame">
                    <header>
                        <h2 data-rishe-role="dialog-title"></h2>
                        <button value="cancel" class="button-link" aria-label="<?php echo esc_attr__('Close', 'rishe'); ?>">×</button>
                    </header>
                    <div data-rishe-role="dialog-body"></div>
                </form>
            </dialog>
        </div>
        <?php
    }

    /** @return array<string, array{title: string, capability: string, icon: string, description: string}> */
    public static function modules(): array
    {
        return self::MODULES;
    }

    private function currentModule(): string
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if (str_starts_with($page, 'rishe-')) {
            return substr($page, 6);
        }

        return 'settings';
    }

    private function moduleFromHook(string $hook): ?string
    {
        foreach (array_keys(self::MODULES) as $module) {
            if (str_contains($hook, 'rishe-' . $module)) {
                return $module;
            }
        }

        return null;
    }
}
