(() => {
    'use strict';

    const cfg = window.risheInitialCost || {};
    const api = window.wp?.apiFetch;
    const app = document.getElementById('rishe-business-app');
    if (!api || !app) return;

    api.use(api.createNonceMiddleware(cfg.nonce));

    const fa = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
    const toman = value => `${fa(Math.round(Number(value || 0)))} تومان`;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    const message = error => error?.message || error?.data?.message || 'عملیات با خطا روبه‌رو شد.';

    let dialog;

    function ensureDialog() {
        if (dialog) return dialog;
        dialog = document.createElement('dialog');
        dialog.className = 'rishe-cost-dialog';
        dialog.innerHTML = `
            <div class="rishe-cost-dialog__frame" dir="rtl" lang="fa">
                <header>
                    <div>
                        <small>ارزش‌گذاری موجودی فعلی</small>
                        <h2>ثبت بهای اولیه</h2>
                    </div>
                    <button type="button" class="rishe-cost-dialog__close" aria-label="بستن">×</button>
                </header>
                <div class="rishe-cost-dialog__body" data-cost-body></div>
            </div>`;
        document.body.appendChild(dialog);
        dialog.querySelector('.rishe-cost-dialog__close').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
        return dialog;
    }

    function averageToman(row) {
        const quantity = Number(row.on_hand || 0);
        if (quantity <= 0) return 0;
        return Math.round((Number(row.inventory_value_irr || 0) / quantity) / 10);
    }

    async function openCostEditor() {
        const modal = ensureDialog();
        const body = modal.querySelector('[data-cost-body]');
        body.innerHTML = '<div class="rishe-cost-loading"><span class="spinner is-active"></span> در حال دریافت موجودی…</div>';
        modal.showModal();

        try {
            const response = await api({path: cfg.stockPath});
            const rows = (response.rows || []).filter(row => Number(row.on_hand || 0) > 0);
            if (!rows.length) {
                body.innerHTML = '<div class="rishe-cost-empty"><strong>موجودی فعالی وجود ندارد.</strong><p>بعد از ورود یا سینک موجودی، بهای اولیه را می‌توان ثبت کرد.</p></div>';
                return;
            }

            body.innerHTML = `
                <div class="rishe-cost-help">
                    <span class="dashicons dashicons-info-outline"></span>
                    <div><strong>این عملیات تعداد کالا را تغییر نمی‌دهد.</strong><p>فقط برای موجودی‌های فعلی که هنوز بهای خرید ندارند قیمت وارد کن. خریدهای بعدی از بخش بازرگانی و تأمین قیمت می‌گیرند.</p></div>
                </div>
                <div class="rishe-cost-toolbar">
                    <input type="search" data-cost-search placeholder="جست‌وجوی کالا یا انبار…">
                </div>
                <div class="rishe-cost-table-wrap">
                    <table class="rishe-cost-table">
                        <thead><tr><th>کالا</th><th>انبار</th><th>موجودی</th><th>بهای فعلی</th><th>قیمت خرید هر واحد</th></tr></thead>
                        <tbody>
                            ${rows.map(row => {
                                const current = averageToman(row);
                                const search = `${row.product_name || ''} ${row.sku || ''} ${row.warehouse_name || ''}`.toLowerCase();
                                const locked = current > 0;
                                return `<tr data-cost-row data-search="${esc(search)}">
                                    <td><strong>${esc(row.product_name)}</strong><small>${esc(row.sku || '')}</small></td>
                                    <td>${esc(row.warehouse_name)}</td>
                                    <td>${fa(row.on_hand)}</td>
                                    <td>${locked ? `<span class="rishe-cost-set">${toman(current)}</span>` : '<span class="rishe-cost-missing">ثبت نشده</span>'}</td>
                                    <td><div class="rishe-cost-input"><input type="number" min="1" step="1" inputmode="numeric" value="${locked ? current : ''}" placeholder="مثلاً 600000" data-product="${Number(row.product_id)}" data-warehouse="${Number(row.warehouse_id)}" data-current="${current}" ${locked ? 'disabled title="این موجودی قبلاً بهاگذاری شده است"' : ''}><span>تومان</span></div></td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="rishe-cost-footer">
                    <div data-cost-result></div>
                    <div><button type="button" class="button" data-cost-cancel>انصراف</button><button type="button" class="button button-primary" data-cost-save>ثبت بهای واردشده</button></div>
                </div>`;

            const search = body.querySelector('[data-cost-search]');
            search.addEventListener('input', () => {
                const needle = search.value.trim().toLowerCase();
                body.querySelectorAll('[data-cost-row]').forEach(row => {
                    row.hidden = needle !== '' && !row.dataset.search.includes(needle);
                });
            });
            body.querySelector('[data-cost-cancel]').addEventListener('click', () => modal.close());
            body.querySelector('[data-cost-save]').addEventListener('click', async event => {
                const button = event.currentTarget;
                const resultBox = body.querySelector('[data-cost-result]');
                const items = [...body.querySelectorAll('[data-cost-row]:not([hidden]) input[data-product]:not(:disabled)')]
                    .map(input => ({
                        product_id: Number(input.dataset.product),
                        warehouse_id: Number(input.dataset.warehouse),
                        unit_cost_toman: Number(input.value || 0)
                    }))
                    .filter(item => item.unit_cost_toman > 0)
                    .map(item => ({
                        product_id: item.product_id,
                        warehouse_id: item.warehouse_id,
                        unit_cost_irr: Math.round(item.unit_cost_toman * 10)
                    }));

                if (!items.length) {
                    resultBox.className = 'is-warning';
                    resultBox.textContent = 'برای حداقل یک کالای بدون بها، قیمت خرید وارد کن.';
                    return;
                }

                button.disabled = true;
                button.textContent = 'در حال ثبت…';
                resultBox.className = '';
                resultBox.textContent = '';
                try {
                    const result = await api({path: cfg.savePath, method: 'POST', data: {items}});
                    resultBox.className = 'is-success';
                    resultBox.textContent = `${fa(result.updated || 0)} ردیف به‌روزرسانی شد${result.skipped ? `؛ ${fa(result.skipped)} ردیف بدون تغییر بود` : ''}.`;
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    resultBox.className = 'is-error';
                    resultBox.textContent = message(error);
                    button.disabled = false;
                    button.textContent = 'ثبت بهای واردشده';
                }
            });
        } catch (error) {
            body.innerHTML = `<div class="rishe-cost-empty is-error"><strong>موجودی دریافت نشد.</strong><p>${esc(message(error))}</p></div>`;
        }
    }

    function attachButton() {
        const actions = app.querySelector('.rishe-section-actions');
        if (!actions || actions.querySelector('[data-initial-cost]')) return false;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rishe-button rishe-button--ghost';
        button.dataset.initialCost = '1';
        button.innerHTML = '<span class="dashicons dashicons-money-alt" aria-hidden="true"></span> ثبت بهای اولیه';
        button.addEventListener('click', openCostEditor);
        actions.appendChild(button);
        return true;
    }

    if (!attachButton()) {
        const observer = new MutationObserver(() => {
            if (attachButton()) observer.disconnect();
        });
        observer.observe(app, {childList: true, subtree: true});
    }
})();
