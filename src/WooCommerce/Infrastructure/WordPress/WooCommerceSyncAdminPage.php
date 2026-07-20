<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Infrastructure\WordPress;

final class WooCommerceSyncAdminPage
{
    private const SLUG = 'rishe-woocommerce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 30);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'rishe',
            __('اتصال ووکامرس', 'rishe'),
            __('اتصال ووکامرس', 'rishe'),
            'rishe_manage_settings',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'rishe_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_script('wp-api-fetch');
        wp_add_inline_style('wp-admin', $this->css());
    }

    public function render(): void
    {
        if (!current_user_can('rishe_manage_settings')) {
            wp_die(esc_html__('شما اجازه مدیریت اتصال ووکامرس را ندارید.', 'rishe'));
        }
        global $wpdb;
        $warehouses = $wpdb->get_results(
            "SELECT id,code,name FROM {$wpdb->prefix}rishe_warehouses WHERE is_active=1 ORDER BY name,id",
            ARRAY_A
        );
        $warehouses = is_array($warehouses) ? $warehouses : [];
        ?>
        <div class="wrap rishe-wc-sync" dir="rtl">
            <h1>اتصال کامل ووکامرس</h1>
            <p class="description">ریشه مرجع موجودی است. سفارش ووکامرس ابتدا موجودی را در ریشه رزرو می‌کند و بعد از پرداخت، خروج انبار قطعی می‌شود.</p>

            <div id="rishe-wc-message" class="notice" hidden><p></p></div>
            <div class="rishe-wc-grid rishe-wc-status" aria-live="polite">
                <div class="rishe-wc-card"><strong>وضعیت اتصال</strong><span data-status="enabled">در حال دریافت…</span></div>
                <div class="rishe-wc-card"><strong>محصول متصل</strong><span data-status="mapped_products">—</span></div>
                <div class="rishe-wc-card"><strong>مغایرت موجودی</strong><span data-status="stock_mismatches">—</span></div>
                <div class="rishe-wc-card"><strong>آخرین اجرا</strong><span data-status="last_run">—</span></div>
            </div>

            <form id="rishe-wc-settings" class="rishe-wc-panel">
                <h2>تنظیمات اتصال</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">انبار مرجع ریشه</th>
                        <td>
                            <select name="warehouse_id" required>
                                <option value="0">انتخاب انبار</option>
                                <?php foreach ($warehouses as $warehouse) : ?>
                                    <option value="<?php echo esc_attr((string) $warehouse['id']); ?>">
                                        <?php echo esc_html((string) $warehouse['name'] . ' (' . (string) $warehouse['code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr><th scope="row">قابلیت‌ها</th><td class="rishe-wc-checks">
                        <?php
                        $checks = [
                            'enabled' => 'فعال‌سازی اتصال',
                            'sync_orders' => 'همگام‌سازی سفارش و وضعیت پرداخت',
                            'sync_refunds' => 'همگام‌سازی مرجوعی و بازگشت موجودی',
                            'sync_stock' => 'همگام‌سازی موجودی',
                            'auto_map_products' => 'اتصال خودکار محصول و Variation',
                            'pull_manual_wc_stock' => 'اعمال اصلاح دستی موجودی ووکامرس در ریشه',
                        ];
                        foreach ($checks as $name => $label) :
                            ?>
                            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1"> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </td></tr>
                    <tr>
                        <th scope="row">مرجع رفع مغایرت</th>
                        <td><select name="reconcile_source"><option value="rishe">ریشه</option><option value="woocommerce">ووکامرس</option></select></td>
                    </tr>
                    <tr>
                        <th scope="row">دوره مغایرت‌گیری</th>
                        <td><select name="reconcile_interval"><option value="fifteen_minutes">هر ۱۵ دقیقه</option><option value="hourly">ساعتی</option><option value="twicedaily">دو بار در روز</option><option value="daily">روزانه</option></select></td>
                    </tr>
                    <tr>
                        <th scope="row">بهای پیش‌فرض ورود موجودی</th>
                        <td><input class="regular-text" type="number" min="0" step="1" name="default_unit_cost_irr" value="0"> <span>ریال</span></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">ذخیره تنظیمات</button></p>
            </form>

            <div class="rishe-wc-panel">
                <h2>راه‌اندازی و عملیات</h2>
                <p>ابتدا محصولات را متصل کنید، سپس تنها یکی از دو گزینه «دریافت اولیه» یا «ارسال اولیه» را متناسب با منبع درست موجودی اجرا کنید.</p>
                <div class="rishe-wc-actions">
                    <button class="button" data-action="products/import">ورود و اتصال محصولات</button>
                    <button class="button" data-action="stock/pull">دریافت موجودی از ووکامرس</button>
                    <button class="button" data-action="stock/push">ارسال موجودی ریشه به ووکامرس</button>
                    <button class="button" data-action="orders/import">ورود سفارش‌های اخیر</button>
                    <button class="button button-secondary" data-action="reconcile">مغایرت‌گیری اکنون</button>
                </div>
                <pre id="rishe-wc-output" hidden></pre>
            </div>

            <div class="rishe-wc-panel rishe-wc-warning">
                <h2>نکته مهم</h2>
                <p>هم‌زمان از افزونه دیگری برای سینک موجودی استفاده نکنید. ریشه برای جلوگیری از کسر دوبرابری، رزرو و کاهش بومی موجودی ووکامرس را برای سفارش‌های متصل مدیریت می‌کند.</p>
            </div>
        </div>
        <script>
        (() => {
            'use strict';
            const api = window.wp && window.wp.apiFetch;
            if (!api) return;
            api.use(api.createNonceMiddleware(<?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>));
            const base = '/rishe/v1/integrations/woocommerce/';
            const form = document.getElementById('rishe-wc-settings');
            const message = document.getElementById('rishe-wc-message');
            const output = document.getElementById('rishe-wc-output');
            const booleanNames = ['enabled','sync_orders','sync_refunds','sync_stock','auto_map_products','pull_manual_wc_stock'];

            const notify = (text, ok = true) => {
                message.hidden = false;
                message.className = 'notice ' + (ok ? 'notice-success' : 'notice-error');
                message.querySelector('p').textContent = text;
            };
            const busy = (button, on) => {
                button.disabled = on;
                button.dataset.label ||= button.textContent;
                button.textContent = on ? 'در حال اجرا…' : button.dataset.label;
            };
            const showResult = (data) => {
                output.hidden = false;
                output.textContent = JSON.stringify(data, null, 2);
            };
            const load = async () => {
                try {
                    const [settings, status] = await Promise.all([
                        api({path: base + 'settings'}),
                        api({path: base + 'status'})
                    ]);
                    Object.entries(settings).forEach(([name, value]) => {
                        const field = form.elements.namedItem(name);
                        if (!field) return;
                        if (field.type === 'checkbox') field.checked = Boolean(value);
                        else field.value = value ?? '';
                    });
                    document.querySelector('[data-status="enabled"]').textContent = status.woocommerce_active
                        ? (status.enabled ? 'فعال' : 'غیرفعال')
                        : 'ووکامرس فعال نیست';
                    document.querySelector('[data-status="mapped_products"]').textContent = String(status.mapped_products ?? 0);
                    document.querySelector('[data-status="stock_mismatches"]').textContent = String(status.stock_mismatches ?? 0);
                    const last = status.last_run && status.last_run.occurred_at ? status.last_run.occurred_at : 'هنوز اجرا نشده';
                    document.querySelector('[data-status="last_run"]').textContent = last;
                    if (status.last_error && status.last_error.message) notify(status.last_error.message, false);
                } catch (error) {
                    notify(error.message || 'دریافت وضعیت اتصال ناموفق بود.', false);
                }
            };
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                busy(button, true);
                const data = Object.fromEntries(new FormData(form).entries());
                booleanNames.forEach(name => data[name] = form.elements.namedItem(name).checked);
                data.warehouse_id = Number(data.warehouse_id || 0);
                data.default_unit_cost_irr = Number(data.default_unit_cost_irr || 0);
                try {
                    await api({path: base + 'settings', method: 'POST', data});
                    notify('تنظیمات اتصال ووکامرس ذخیره شد.');
                    await load();
                } catch (error) {
                    notify(error.message || 'ذخیره تنظیمات ناموفق بود.', false);
                } finally {
                    busy(button, false);
                }
            });
            document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
                busy(button, true);
                try {
                    const data = await api({path: base + button.dataset.action, method: 'POST', data: {}});
                    showResult(data);
                    notify('عملیات با موفقیت انجام شد.');
                    await load();
                } catch (error) {
                    notify(error.message || 'اجرای عملیات ناموفق بود.', false);
                } finally {
                    busy(button, false);
                }
            }));
            load();
        })();
        </script>
        <?php
    }

    private function css(): string
    {
        return '.rishe-wc-sync{max-width:1100px}.rishe-wc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:20px 0}.rishe-wc-card,.rishe-wc-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.rishe-wc-card strong,.rishe-wc-card span{display:block}.rishe-wc-card span{font-size:20px;margin-top:10px}.rishe-wc-panel{margin:16px 0}.rishe-wc-checks{display:grid;gap:10px}.rishe-wc-actions{display:flex;flex-wrap:wrap;gap:10px}.rishe-wc-warning{border-right:4px solid #dba617}#rishe-wc-output{direction:ltr;text-align:left;background:#111827;color:#e5e7eb;padding:14px;border-radius:8px;max-height:360px;overflow:auto;margin-top:15px}@media(max-width:800px){.rishe-wc-grid{grid-template-columns:1fr 1fr}}';
    }
}
