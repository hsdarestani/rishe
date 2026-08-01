<?php

declare(strict_types=1);

namespace Rishe\Accounting\Infrastructure\WordPress;

final class AccountingReviewAdminPage
{
    public const SLUG = 'rishe-accounting-review';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('parent_file', [$this, 'parentFile']);
        add_filter('submenu_file', [$this, 'submenuFile']);
    }

    public function assets(string $hook): void
    {
        unset($hook);
        if (!$this->isCurrentPage()) {
            return;
        }
        wp_enqueue_style(
            'rishe-accounting-review',
            RISHE_URL . 'assets/admin/accounting-review.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-accounting-review',
            RISHE_URL . 'assets/admin/accounting-review.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-accounting-review', 'risheAccountingReview', [
            'root' => '/rishe/v1/accounting',
            'nonce' => wp_create_nonce('wp_rest'),
            'today' => gmdate('Y-m-d'),
            'fiscalYear' => 1405,
            'currency' => 'تومان',
        ]);
    }

    public function parentFile(string $parentFile): string
    {
        return $this->isCurrentPage() ? 'rishe' : $parentFile;
    }

    public function submenuFile(?string $submenuFile): ?string
    {
        return $this->isCurrentPage() ? 'rishe-work-finance' : $submenuFile;
    }

    public function render(): void
    {
        if (!current_user_can('rishe_manage_accounting') && !current_user_can('manage_rishe')) {
            wp_die(esc_html__('شما اجازه بررسی اسناد حسابداری را ندارید.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-review" id="rishe-accounting-review" dir="rtl" lang="fa">
            <a class="rishe-review__back" href="<?php echo esc_url(admin_url('admin.php?page=rishe-work-finance')); ?>">
                <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                بازگشت به مالی و حسابداری
            </a>
            <header class="rishe-review__hero">
                <div>
                    <p>مالی و حسابداری ریشه</p>
                    <h1>کارتابل تأیید اسناد</h1>
                    <span>فروش، خرید، خزانه، منابع انسانی، اجاره، پیمانکار و سایر رویدادهای مالی در یک صف بررسی.</span>
                </div>
                <button class="button button-primary button-hero" type="button" data-create-document>ثبت سند جدید</button>
            </header>

            <div class="rishe-review__notice" data-notice hidden></div>
            <section class="rishe-review__stats" data-stats></section>
            <section class="rishe-review__toolbar">
                <div class="rishe-review__tabs" data-tabs>
                    <button type="button" class="is-active" data-status="pending">منتظر بررسی</button>
                    <button type="button" data-status="approved">تأییدشده</button>
                    <button type="button" data-status="rejected">ردشده</button>
                    <button type="button" data-status="all">همه</button>
                </div>
                <select data-source-filter aria-label="فیلتر منبع">
                    <option value="">همه منابع</option>
                    <option value="sales">فروش</option>
                    <option value="purchase">خرید و تأمین</option>
                    <option value="treasury">بانک و خزانه</option>
                    <option value="b2b">فروش B2B</option>
                    <option value="logistics">لجستیک</option>
                    <option value="payroll">منابع انسانی و حقوق</option>
                    <option value="rent">اجاره</option>
                    <option value="contractor">پیمانکار</option>
                    <option value="operating_expense">هزینه جاری</option>
                    <option value="manual">سند دستی</option>
                </select>
            </section>

            <main class="rishe-review__content" data-content>
                <div class="rishe-review__loading"><span class="spinner is-active"></span> در حال دریافت اسناد…</div>
            </main>

            <dialog class="rishe-review__dialog" data-dialog>
                <div class="rishe-review__dialog-frame">
                    <header><h2 data-dialog-title></h2><button type="button" data-close aria-label="بستن">×</button></header>
                    <div data-dialog-body></div>
                </div>
            </dialog>
        </div>
        <?php
    }

    private function isCurrentPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

        return $page === self::SLUG;
    }
}
