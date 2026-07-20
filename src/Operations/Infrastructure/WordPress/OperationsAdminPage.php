<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\WordPress;

final class OperationsAdminPage
{
    /** @var list<string> */
    private const PAGE_HOOKS = [
        'toplevel_page_rishe',
        'rishe_page_rishe-operations',
        'rishe-erp_page_rishe-operations',
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
        wp_enqueue_style(
            'rishe-operations-admin',
            RISHE_URL . 'assets/admin/operations.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-operations-admin',
            RISHE_URL . 'assets/admin/operations.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-operations-admin', 'risheOperations', [
            'root' => '/rishe/v1/operations',
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => RISHE_VERSION,
            'databaseVersion' => (string) get_option('rishe_db_version', ''),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('rishe_manage_operations')) {
            wp_die(esc_html__('شما اجازه مدیریت عملیات سامانه ریشه را ندارید.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-ops" id="rishe-operations-app" dir="rtl" lang="fa">
            <div class="rishe-ops__hero">
                <div>
                    <p class="rishe-ops__eyebrow"><?php echo esc_html__('مرکز کنترل عملیات', 'rishe'); ?></p>
                    <h1><?php echo esc_html__('سامانه ریشه', 'rishe'); ?></h1>
                    <p><?php echo esc_html__('اتصال‌ها، کارهای پس‌زمینه، خطاها و سلامت سامانه را از یک صفحه مدیریت کنید.', 'rishe'); ?></p>
                </div>
                <div class="rishe-ops__hero-actions">
                    <span class="rishe-ops__version">نسخه <?php echo esc_html(RISHE_VERSION); ?></span>
                    <button type="button" class="button button-primary" data-rishe-action="refresh">
                        <?php echo esc_html__('تازه‌سازی', 'rishe'); ?>
                    </button>
                </div>
            </div>

            <div class="notice notice-error inline hidden" data-rishe-role="error"><p></p></div>
            <div class="notice notice-success inline hidden" data-rishe-role="success"><p></p></div>

            <section class="rishe-ops__cards" data-rishe-role="metrics" aria-live="polite"></section>

            <div class="rishe-ops__layout">
                <section class="rishe-ops__panel rishe-ops__panel--wide">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('پردازش پس‌زمینه', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('کارهای پس‌زمینه', 'rishe'); ?></h2>
                        </div>
                        <span class="rishe-ops__badge" data-rishe-role="scheduler">—</span>
                    </div>
                    <form class="rishe-ops__job-form" data-rishe-role="job-form">
                        <label>
                            <span><?php echo esc_html__('نوع کار', 'rishe'); ?></span>
                            <select name="job_type" required data-rishe-role="job-types"></select>
                        </label>
                        <label>
                            <span><?php echo esc_html__('شناسه صورتحساب یا مرسوله', 'rishe'); ?></span>
                            <input name="aggregate_id" type="number" min="1" required>
                        </label>
                        <label>
                            <span><?php echo esc_html__('کلید جلوگیری از ثبت تکراری', 'rishe'); ?></span>
                            <input name="idempotency_key" type="text" maxlength="191" required>
                        </label>
                        <button class="button button-primary" type="submit"><?php echo esc_html__('افزودن به صف', 'rishe'); ?></button>
                    </form>
                    <div class="rishe-ops__table-wrap">
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php echo esc_html__('شناسه', 'rishe'); ?></th>
                                <th><?php echo esc_html__('نوع', 'rishe'); ?></th>
                                <th><?php echo esc_html__('مرجع', 'rishe'); ?></th>
                                <th><?php echo esc_html__('وضعیت', 'rishe'); ?></th>
                                <th><?php echo esc_html__('تعداد تلاش', 'rishe'); ?></th>
                                <th><?php echo esc_html__('زمان‌بندی', 'rishe'); ?></th>
                                <th><?php echo esc_html__('عملیات', 'rishe'); ?></th>
                            </tr></thead>
                            <tbody data-rishe-role="jobs"><tr><td colspan="7"><?php echo esc_html__('در حال دریافت…', 'rishe'); ?></td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('سلامت سامانه', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('بررسی سلامت', 'rishe'); ?></h2>
                        </div>
                        <span class="rishe-ops__status" data-rishe-role="health-status">—</span>
                    </div>
                    <div class="rishe-ops__diagnostics" data-rishe-role="diagnostics"></div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('نیازمند رسیدگی', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('رخدادهای باز', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <div data-rishe-role="incidents"></div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('جابه‌جایی امن تنظیمات', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('بسته تنظیمات', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <p><?php echo esc_html__('فقط تنظیمات غیرمحرمانه مجاز صادر می‌شوند و ورود تنظیمات پس از پیش‌نمایش و تأیید انجام می‌شود.', 'rishe'); ?></p>
                    <div class="rishe-ops__config-actions">
                        <button type="button" class="button" data-rishe-action="export-config"><?php echo esc_html__('دریافت فایل تنظیمات', 'rishe'); ?></button>
                        <label class="button">
                            <?php echo esc_html__('انتخاب فایل تنظیمات', 'rishe'); ?>
                            <input type="file" accept="application/json,.json" hidden data-rishe-role="import-file">
                        </label>
                    </div>
                    <div class="rishe-ops__config-preview" data-rishe-role="config-preview"></div>
                </section>

                <section class="rishe-ops__panel rishe-ops__panel--wide">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('سابقه غیرقابل‌تغییر', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('آخرین رویدادهای نظارتی', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <div class="rishe-ops__table-wrap">
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php echo esc_html__('زمان', 'rishe'); ?></th>
                                <th><?php echo esc_html__('رویداد', 'rishe'); ?></th>
                                <th><?php echo esc_html__('رکورد مرتبط', 'rishe'); ?></th>
                                <th><?php echo esc_html__('انجام‌دهنده', 'rishe'); ?></th>
                                <th><?php echo esc_html__('شناسه پیگیری', 'rishe'); ?></th>
                            </tr></thead>
                            <tbody data-rishe-role="audit"><tr><td colspan="5"><?php echo esc_html__('در حال دریافت…', 'rishe'); ?></td></tr></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }
}
