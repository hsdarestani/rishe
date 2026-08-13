<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use Rishe\Accounting\Infrastructure\WordPress\AccountingReviewAdminPage;
use Rishe\EventSales\Infrastructure\WordPress\EventSalesAdminPage;

final class BusinessAdminPage
{
    /** @var array<string, array{section:string,title:string,description:string,capability:string}> */
    private const PAGES = [
        'rishe' => [
            'section' => 'dashboard',
            'title' => 'مرکز فرمان ریشه',
            'description' => 'تصویر امروز کسب‌وکار، کارهای منتظر اقدام و وضعیت کانال‌های فروش.',
            'capability' => 'rishe_access_app',
        ],
        'rishe-work-inventory' => [
            'section' => 'inventory',
            'title' => 'انبار و بسته‌بندی',
            'description' => 'موجودی همه انبارها، ورود و انتقال کالا، بسته‌بندی، ضایعات و انبارگردانی.',
            'capability' => 'rishe_manage_inventory',
        ],
        'rishe-work-sales' => [
            'section' => 'sales',
            'title' => 'فروش و بازاریابی',
            'description' => 'فروش سایت، ایونت و دکان بدون ثبت سفارش تکراری خارج از ووکامرس.',
            'capability' => 'rishe_manage_sales',
        ],
        'rishe-work-procurement' => [
            'section' => 'procurement',
            'title' => 'بازرگانی و تأمین',
            'description' => 'تأمین‌کنندگان، سفارش خرید، فاکتور، دریافت کالا و برنامه تسویه.',
            'capability' => 'rishe_manage_procurement',
        ],
        'rishe-work-finance' => [
            'section' => 'finance',
            'title' => 'مالی و حسابداری',
            'description' => 'سند افتتاحیه، اسناد پیشنهادی، خزانه، بانک و گزارش‌های مالی موقت.',
            'capability' => 'rishe_manage_accounting',
        ],
        'rishe-work-logistics' => [
            'section' => 'logistics',
            'title' => 'لجستیک',
            'description' => 'انتقال بین انبارها، دریافت از تأمین‌کننده و ارسال B2B و خرده‌فروشی.',
            'capability' => 'rishe_manage_logistics',
        ],
        'rishe-work-b2b' => [
            'section' => 'b2b',
            'title' => 'فروش B2B',
            'description' => 'سفارش وندور، کنترل اعتبار، آماده‌سازی، ارسال و تسویه مشتری سازمانی.',
            'capability' => 'rishe_manage_b2b',
        ],
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        unset($hook);
        $page = $this->currentPage();
        if (!isset(self::PAGES[$page])) {
            return;
        }

        wp_enqueue_style(
            'rishe-business-admin',
            RISHE_URL . 'assets/admin/business.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-business-admin',
            RISHE_URL . 'assets/admin/business.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );

        $definition = self::PAGES[$page];
        wp_localize_script('rishe-business-admin', 'risheBusiness', [
            'section' => $definition['section'],
            'root' => '/rishe/v1',
            'businessRoot' => '/rishe/v1/business',
            'nonce' => wp_create_nonce('wp_rest'),
            'today' => gmdate('Y-m-d'),
            'monthStart' => gmdate('Y-m-01'),
            'version' => RISHE_VERSION,
            'woocommerceActive' => class_exists('WooCommerce'),
            'currency' => 'تومان',
            'readOnly' => $this->isReadOnlyViewer(),
            'links' => [
                'dashboard' => admin_url('admin.php?page=rishe'),
                'inventory' => admin_url('admin.php?page=rishe-work-inventory'),
                'sales' => admin_url('admin.php?page=rishe-work-sales'),
                'procurement' => admin_url('admin.php?page=rishe-work-procurement'),
                'finance' => admin_url('admin.php?page=rishe-work-finance'),
                'logistics' => admin_url('admin.php?page=rishe-work-logistics'),
                'b2b' => admin_url('admin.php?page=rishe-work-b2b'),
                'wooOrders' => admin_url('admin.php?page=wc-orders'),
                'wooProducts' => admin_url('edit.php?post_type=product'),
                'wooCustomers' => admin_url('admin.php?page=wc-admin&path=/customers'),
                'advancedInventory' => admin_url('admin.php?page=rishe-inventory'),
                'advancedSales' => admin_url('admin.php?page=rishe-sales'),
                'advancedProcurement' => admin_url('admin.php?page=rishe-procurement'),
                'advancedAccounting' => admin_url('admin.php?page=rishe-accounting'),
                'advancedTreasury' => admin_url('admin.php?page=rishe-treasury'),
                'advancedLogistics' => admin_url('admin.php?page=rishe-logistics'),
                'advancedB2b' => admin_url('admin.php?page=rishe-b2b'),
                'manufacturing' => admin_url('admin.php?page=rishe-manufacturing'),
                'analytics' => admin_url('admin.php?page=rishe-analytics'),
                'operations' => admin_url('admin.php?page=rishe-operations'),
                'settings' => admin_url('admin.php?page=rishe-settings'),
            ],
            'capabilities' => [
                'inventory' => current_user_can('rishe_manage_inventory'),
                'sales' => current_user_can('rishe_manage_sales'),
                'procurement' => current_user_can('rishe_manage_procurement'),
                'finance' => current_user_can('rishe_manage_accounting'),
                'treasury' => current_user_can('rishe_manage_treasury'),
                'logistics' => current_user_can('rishe_manage_logistics'),
                'b2b' => current_user_can('rishe_manage_b2b'),
                'reports' => current_user_can('rishe_view_reports'),
                'viewAllSections' => current_user_can('rishe_view_all_sections'),
            ],
        ]);
    }

    public function render(): void
    {
        $page = $this->currentPage();
        $definition = self::PAGES[$page] ?? self::PAGES['rishe'];
        if (
            !current_user_can($definition['capability'])
            && !current_user_can('rishe_view_all_sections')
            && !current_user_can('manage_rishe')
        ) {
            wp_die(esc_html__('شما اجازه دسترسی به این بخش از ریشه را ندارید.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-business<?php echo $this->isReadOnlyViewer() ? ' is-read-only' : ''; ?>" id="rishe-business-app" dir="rtl" lang="fa">
            <header class="rishe-business__header">
                <div class="rishe-business__brand">
                    <span class="rishe-business__mark" aria-hidden="true">
                        <svg viewBox="0 0 48 48" role="img"><path d="M24 41V20M24 26c-7 0-12-4-14-11 8-1 13 2 14 11Zm0-5c7 0 12-4 14-11-8-1-13 2-14 11ZM24 35c-5 0-8 2-10 6m10-6c5 0 8 2 10 6"/></svg>
                    </span>
                    <div>
                        <p>سیستم عملیاتی کسب‌وکار ریشه</p>
                        <h1><?php echo esc_html($definition['title']); ?></h1>
                        <span><?php echo esc_html($definition['description']); ?></span>
                    </div>
                </div>
                <div class="rishe-business__header-actions">
                    <span class="rishe-business__version">نسخه <?php echo esc_html(RISHE_VERSION); ?></span>
                    <button type="button" class="rishe-button rishe-button--ghost" data-rishe-refresh>تازه‌سازی</button>
                </div>
            </header>

            <nav class="rishe-business__nav" aria-label="بخش‌های اصلی ریشه">
                <?php foreach ($this->navigation() as $slug => $item) : ?>
                    <?php if (!$this->canAccess($item['capability'])) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>" class="<?php echo $slug === $page ? 'is-active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                        <?php echo esc_html($item['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($this->isReadOnlyViewer()) : ?>
                <section class="rishe-source is-connected rishe-read-only-notice">
                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                    <div>
                        <strong>حالت فقط گزارش</strong>
                        <p>تمام بخش‌ها قابل مشاهده‌اند؛ ثبت، ویرایش، تأیید و حذف برای این حساب غیرفعال است.</p>
                    </div>
                </section>
            <?php endif; ?>

            <?php $this->renderWorkspaceEntry($page); ?>

            <div class="rishe-business__notice hidden" data-rishe-notice></div>
            <main class="rishe-business__main" data-rishe-content>
                <div class="rishe-business__loading">
                    <span class="spinner is-active"></span>
                    <strong>در حال آماده‌سازی اطلاعات…</strong>
                </div>
            </main>

            <dialog class="rishe-business__dialog" data-rishe-dialog>
                <form method="dialog" class="rishe-business__dialog-frame">
                    <header>
                        <div>
                            <p data-rishe-dialog-eyebrow>عملیات ریشه</p>
                            <h2 data-rishe-dialog-title></h2>
                        </div>
                        <button value="cancel" class="rishe-business__dialog-close" aria-label="بستن">×</button>
                    </header>
                    <div data-rishe-dialog-body></div>
                </form>
            </dialog>
        </div>
        <?php
    }

    private function renderWorkspaceEntry(string $page): void
    {
        if ($page === 'rishe-work-finance' && current_user_can('rishe_manage_accounting')) {
            ?>
            <section class="rishe-source is-connected">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <div>
                    <strong>کارتابل تأیید اسناد مالی</strong>
                    <p>اسناد فروش، خرید، حقوق، اجاره و سایر هزینه‌ها را بررسی، تأیید یا رد کنید.</p>
                </div>
                <a class="rishe-button rishe-button--light" href="<?php echo esc_url(admin_url('admin.php?page=' . AccountingReviewAdminPage::SLUG)); ?>">
                    ورود به کارتابل
                </a>
            </section>
            <?php
        }

        if ($page === 'rishe-work-sales' && current_user_can('rishe_manage_sales')) {
            ?>
            <section class="rishe-source is-warning">
                <span class="dashicons dashicons-store" aria-hidden="true"></span>
                <div>
                    <strong>فروش حضوری و ایونت</strong>
                    <p>ایونت، انبار و فروشنده را تعریف کنید و فروش‌های آفلاین موبایل را مدیریت کنید.</p>
                </div>
                <a class="rishe-button" href="<?php echo esc_url(admin_url('admin.php?page=' . EventSalesAdminPage::SLUG)); ?>">
                    مدیریت ایونت‌ها
                </a>
            </section>
            <?php
        }
    }

    /** @return array<string, array{label:string,icon:string,capability:string}> */
    private function navigation(): array
    {
        return [
            'rishe' => ['label' => 'مرکز فرمان', 'icon' => 'dashicons-dashboard', 'capability' => 'rishe_access_app'],
            'rishe-work-inventory' => ['label' => 'انبار', 'icon' => 'dashicons-archive', 'capability' => 'rishe_manage_inventory'],
            'rishe-work-sales' => ['label' => 'فروش و بازاریابی', 'icon' => 'dashicons-chart-line', 'capability' => 'rishe_manage_sales'],
            'rishe-work-procurement' => ['label' => 'بازرگانی و تأمین', 'icon' => 'dashicons-store', 'capability' => 'rishe_manage_procurement'],
            'rishe-work-finance' => ['label' => 'مالی و حسابداری', 'icon' => 'dashicons-money-alt', 'capability' => 'rishe_manage_accounting'],
            'rishe-work-logistics' => ['label' => 'لجستیک', 'icon' => 'dashicons-location-alt', 'capability' => 'rishe_manage_logistics'],
            'rishe-work-b2b' => ['label' => 'فروش B2B', 'icon' => 'dashicons-groups', 'capability' => 'rishe_manage_b2b'],
        ];
    }

    private function canAccess(string $capability): bool
    {
        return current_user_can('manage_rishe')
            || current_user_can('rishe_view_all_sections')
            || current_user_can($capability);
    }

    private function isReadOnlyViewer(): bool
    {
        return current_user_can('rishe_view_all_sections') && !current_user_can('manage_rishe');
    }

    private function currentPage(): string
    {
        return isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : 'rishe';
    }
}
