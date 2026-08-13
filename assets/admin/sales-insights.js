(() => {
'use strict';

const cfg = window.risheSalesInsights || {};
const api = window.wp?.apiFetch;
const root = document.getElementById('rishe-sales-insights');
if (!api || !root) return;

api.use(api.createNonceMiddleware(cfg.nonce));

const content = root.querySelector('[data-content]');
const notice = root.querySelector('[data-notice]');
let activeTab = root.querySelector('[data-tab].is-active')?.dataset.tab || 'sales';
let customerPage = 1;
let lastSalesRows = [];
let lastCustomerRows = [];

const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
}[char]));
const num = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
const toman = value => `${num(Math.round(Number(value || 0) / 10))} تومان`;
const apiCall = (path, options = {}) => api({ path: `${cfg.root}${path}`, ...options });

function showNotice(message, error = false) {
  notice.hidden = false;
  notice.className = `rishe-insights__notice${error ? ' is-error' : ''}`;
  notice.textContent = message;
  clearTimeout(showNotice.timer);
  showNotice.timer = setTimeout(() => { notice.hidden = true; }, 5000);
}

function loading(text = 'در حال دریافت اطلاعات…') {
  content.innerHTML = `<div class="rishe-insights__loading"><span class="spinner is-active"></span>${esc(text)}</div>`;
}

function params(object) {
  const query = new URLSearchParams();
  Object.entries(object).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
  });
  return query.toString();
}

function optionRows(rows, selected = '', placeholder = 'همه') {
  return `<option value="">${esc(placeholder)}</option>${(rows || []).map(row => {
    const value = row.id ?? row;
    const label = row.label ?? row;
    return `<option value="${esc(value)}" ${String(value) === String(selected) ? 'selected' : ''}>${esc(label)}</option>`;
  }).join('')}`;
}

function kpi(title, value, hint = '', tone = '') {
  return `<article class="rishe-insights__kpi ${tone ? `is-${tone}` : ''}"><small>${esc(title)}</small><strong>${value}</strong><em>${esc(hint)}</em></article>`;
}

function channelLabel(channel) {
  const labels = {
    website: 'سایت', checkout: 'سایت', admin: 'مدیریت', event: 'ایونت',
    b2b: 'B2B', store: 'دکان', pos: 'دکان'
  };
  return labels[channel] || channel || 'نامشخص';
}

function badge(text) {
  return `<span class="rishe-badge-mini">${esc(text)}</span>`;
}

function exportCsv(filename, headers, rows) {
  const quote = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const csv = '\uFEFF' + [headers, ...rows].map(row => row.map(quote).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function salesFiltersFromDom() {
  const form = root.querySelector('[data-sales-filters]');
  if (!form) {
    return {
      from: cfg.monthStart,
      to: cfg.today,
      target_month: cfg.month,
      channel: '',
      status: '',
      seller_user_id: '',
      event_id: '',
      customer: '',
      product_id: ''
    };
  }
  const data = new FormData(form);
  return Object.fromEntries(data.entries());
}

async function renderSales(filters = null) {
  const f = filters || salesFiltersFromDom();
  loading('در حال محاسبه فروش، تارگت و انحراف…');
  try {
    const report = await apiCall(`/report?${params(f)}`);
    lastSalesRows = report.rows || [];
    const summary = report.summary || {};
    const target = report.target || {};
    const opts = report.filters || {};
    const deviation = Number(target.deviation_irr || 0);
    const deviationTone = deviation >= 0 ? 'good' : 'bad';
    const progress = Math.max(0, Math.min(100, Number(target.progress_percent || 0)));

    content.innerHTML = `
      <form class="rishe-insights__filters" data-sales-filters>
        <label>از تاریخ<input type="date" name="from" value="${esc(report.from)}"></label>
        <label>تا تاریخ<input type="date" name="to" value="${esc(report.to)}"></label>
        <label>کانال<select name="channel">${optionRows((opts.channels || []).map(x => ({ id: x, label: channelLabel(x) })), f.channel)}</select></label>
        <label>وضعیت سفارش<select name="status">${optionRows(opts.statuses, f.status)}</select></label>
        <label>فروشنده<select name="seller_user_id">${optionRows(opts.sellers, f.seller_user_id)}</select></label>
        <label>ایونت<select name="event_id">${optionRows(opts.events, f.event_id)}</select></label>
        <label>مشتری<input name="customer" value="${esc(f.customer || '')}" placeholder="نام، موبایل یا ایمیل"></label>
        <label>کالا<select name="product_id">${optionRows(opts.products, f.product_id)}</select></label>
        <label>ماه تارگت<input type="month" name="target_month" value="${esc(target.month || cfg.month)}"></label>
        <div class="rishe-insights__filter-actions"><button class="rishe-action" type="submit">اعمال فیلتر</button><button class="rishe-action is-light" type="button" data-sales-reset>پاک‌کردن</button></div>
      </form>

      <div class="rishe-insights__kpis">
        ${kpi('فروش خالص', toman(summary.sales_irr), `${num(summary.orders)} سفارش`, 'good')}
        ${kpi('میانگین سبد', toman(summary.average_order_irr), `${num(summary.customers)} مشتری`)}
        ${kpi('تخفیف', toman(summary.discount_irr), 'در بازه فیلترشده')}
        ${kpi('تارگت ماه', toman(target.target_irr), target.month || '')}
        ${kpi('انحراف از تارگت', toman(deviation), target.deviation_percent === null ? 'تارگت تعریف نشده' : `${num(target.deviation_percent)}٪`, deviationTone)}
      </div>

      <section class="rishe-insights__target">
        <label>ماه تارگت<input type="month" data-target-month value="${esc(target.month || cfg.month)}"></label>
        <label>تارگت فروش (تومان)<input type="number" min="0" data-target-value value="${Math.round(Number(target.target_irr || 0) / 10)}" ${report.can_manage_target ? '' : 'disabled'}></label>
        <div class="rishe-target-copy"><strong>عملکرد ماه: ${toman(target.actual_irr)}</strong><span>${target.progress_percent === null ? 'برای این ماه تارگت ثبت نشده.' : `${num(target.progress_percent)}٪ از تارگت محقق شده است.`}</span><div class="rishe-target-progress"><i style="width:${progress}%"></i></div></div>
        ${report.can_manage_target ? '<button class="rishe-action" type="button" data-target-save>ذخیره تارگت</button>' : ''}
      </section>

      <section class="rishe-insights__panel">
        <header><div><h2>جزئیات فروش</h2><p>${num(lastSalesRows.length)} سفارش مطابق فیلترها</p></div><div class="rishe-insights__toolbar"><button type="button" data-export-sales>خروجی CSV</button></div></header>
        ${renderSalesTable(lastSalesRows)}
      </section>`;

    root.querySelector('[data-sales-filters]').addEventListener('submit', event => {
      event.preventDefault();
      renderSales(salesFiltersFromDom());
    });
    root.querySelector('[data-sales-reset]').addEventListener('click', () => renderSales({
      from: cfg.monthStart,
      to: cfg.today,
      target_month: cfg.month,
      channel: '', status: '', seller_user_id: '', event_id: '', customer: '', product_id: ''
    }));
    root.querySelector('[data-target-save]')?.addEventListener('click', saveTarget);
    root.querySelector('[data-export-sales]').addEventListener('click', exportSales);
  } catch (error) {
    content.innerHTML = `<div class="rishe-insights__empty">${esc(error?.message || 'گزارش فروش بارگذاری نشد.')}</div>`;
  }
}

function renderSalesTable(rows) {
  if (!rows.length) return '<div class="rishe-insights__empty">فروشی مطابق فیلترها پیدا نشد.</div>';
  return `<div class="rishe-insights__table-wrap"><table><thead><tr><th>سفارش</th><th>زمان</th><th>کانال</th><th>ایونت / فروشنده</th><th>مشتری</th><th>کالاها</th><th>وضعیت</th><th>روش پرداخت</th><th>جمع قبل تخفیف</th><th>تخفیف</th><th>مبلغ نهایی</th></tr></thead><tbody>${rows.map(row => `
    <tr>
      <td><strong>#${esc(row.number)}</strong><small>ID ${esc(row.order_id)}</small></td>
      <td>${esc(row.created_at)}</td>
      <td>${badge(channelLabel(row.channel))}</td>
      <td><strong>${esc(row.event_name || '—')}</strong><small>${esc(row.seller_name || '—')}</small></td>
      <td><strong>${esc(row.customer_name)}</strong><small>${esc(row.phone || row.email || 'بدون تماس')}</small></td>
      <td><small title="${esc((row.products || []).join('، '))}">${esc((row.products || []).join('، '))}</small></td>
      <td>${badge(row.status_label)}</td>
      <td>${esc(row.payment_method || '—')}</td>
      <td>${toman(row.subtotal_irr)}</td>
      <td>${toman(row.discount_irr)}</td>
      <td><strong>${toman(row.total_irr)}</strong></td>
    </tr>`).join('')}</tbody></table></div>`;
}

async function saveTarget() {
  const month = root.querySelector('[data-target-month]').value;
  const value = Math.max(0, Number(root.querySelector('[data-target-value]').value || 0));
  try {
    await apiCall('/target', { method: 'POST', data: { month, target_irr: Math.round(value * 10) } });
    showNotice('تارگت ماهانه ذخیره شد.');
    const filters = salesFiltersFromDom();
    filters.target_month = month;
    renderSales(filters);
  } catch (error) {
    showNotice(error?.message || 'ذخیره تارگت انجام نشد.', true);
  }
}

function exportSales() {
  exportCsv(`rishe-sales-${new Date().toISOString().slice(0, 10)}.csv`, [
    'شماره سفارش', 'زمان', 'کانال', 'ایونت', 'فروشنده', 'مشتری', 'موبایل', 'ایمیل',
    'کالاها', 'وضعیت', 'روش پرداخت', 'جمع قبل تخفیف (ریال)', 'تخفیف (ریال)', 'مبلغ نهایی (ریال)'
  ], lastSalesRows.map(row => [
    row.number, row.created_at, channelLabel(row.channel), row.event_name, row.seller_name,
    row.customer_name, row.phone, row.email, (row.products || []).join(' | '), row.status_label,
    row.payment_method, row.subtotal_irr, row.discount_irr, row.total_irr
  ]));
}

function customerFiltersFromDom() {
  const form = root.querySelector('[data-customer-filters]');
  if (!form) return { search: '', channel: '', min_orders: 0, sort: 'recent', page: customerPage, per_page: 50 };
  const data = Object.fromEntries(new FormData(form).entries());
  data.page = customerPage;
  data.per_page = 50;
  return data;
}

async function renderCustomers(filters = null) {
  const f = filters || customerFiltersFromDom();
  f.page = customerPage;
  loading('در حال ساخت دیتابیس یکپارچه مشتریان…');
  try {
    const result = await apiCall(`/customers?${params(f)}`);
    lastCustomerRows = result.rows || [];
    const s = result.summary || {};
    content.innerHTML = `
      <form class="rishe-insights__filters" data-customer-filters>
        <label>جست‌وجو<input name="search" value="${esc(f.search || '')}" placeholder="نام، موبایل یا ایمیل"></label>
        <label>کانال<select name="channel"><option value="">همه</option>${['website','event','b2b','store','admin'].map(channel => `<option value="${channel}" ${f.channel === channel ? 'selected' : ''}>${esc(channelLabel(channel))}</option>`).join('')}</select></label>
        <label>حداقل تعداد خرید<input type="number" name="min_orders" min="0" value="${esc(f.min_orders || 0)}"></label>
        <label>مرتب‌سازی<select name="sort"><option value="recent" ${f.sort === 'recent' ? 'selected' : ''}>آخرین خرید</option><option value="spend" ${f.sort === 'spend' ? 'selected' : ''}>بیشترین خرید</option><option value="orders" ${f.sort === 'orders' ? 'selected' : ''}>بیشترین سفارش</option><option value="name" ${f.sort === 'name' ? 'selected' : ''}>نام</option></select></label>
        <div class="rishe-insights__filter-actions"><button class="rishe-action" type="submit">اعمال فیلتر</button><button class="rishe-action is-light" type="button" data-customer-reset>پاک‌کردن</button></div>
      </form>
      <div class="rishe-insights__customer-summary">
        <div><small>کل مشتریان</small><strong>${num(s.customers)}</strong></div>
        <div><small>مشتری ووکامرسی</small><strong>${num(s.registered_customers)}</strong></div>
        <div><small>مشتری مهمان قدیمی</small><strong>${num(s.guest_customers)}</strong></div>
        <div><small>فروش تجمیعی</small><strong>${toman(s.sales_irr)}</strong></div>
      </div>
      <section class="rishe-insights__panel">
        <header><div><h2>دیتابیس مشتریان</h2><p>${num(result.total)} مشتری مطابق فیلترها${result.truncated ? ' · داده سفارش‌ها به سقف پردازش رسیده' : ''}</p></div><div class="rishe-insights__toolbar"><button type="button" data-export-customers>خروجی CSV این صفحه</button></div></header>
        ${renderCustomersTable(lastCustomerRows)}
        <div class="rishe-insights__pagination"><button type="button" data-prev ${result.page <= 1 ? 'disabled' : ''}>قبلی</button><span>صفحه ${num(result.page)} از ${num(result.pages)}</span><button type="button" data-next ${result.page >= result.pages ? 'disabled' : ''}>بعدی</button></div>
      </section>`;

    root.querySelector('[data-customer-filters]').addEventListener('submit', event => {
      event.preventDefault();
      customerPage = 1;
      renderCustomers(customerFiltersFromDom());
    });
    root.querySelector('[data-customer-reset]').addEventListener('click', () => {
      customerPage = 1;
      renderCustomers({ search: '', channel: '', min_orders: 0, sort: 'recent', page: 1, per_page: 50 });
    });
    root.querySelector('[data-prev]')?.addEventListener('click', () => {
      if (customerPage > 1) customerPage -= 1;
      renderCustomers(customerFiltersFromDom());
    });
    root.querySelector('[data-next]')?.addEventListener('click', () => {
      customerPage += 1;
      renderCustomers(customerFiltersFromDom());
    });
    root.querySelector('[data-export-customers]').addEventListener('click', exportCustomers);
  } catch (error) {
    content.innerHTML = `<div class="rishe-insights__empty">${esc(error?.message || 'دیتابیس مشتریان بارگذاری نشد.')}</div>`;
  }
}

function renderCustomersTable(rows) {
  if (!rows.length) return '<div class="rishe-insights__empty">مشتری مطابق فیلترها پیدا نشد.</div>';
  return `<div class="rishe-insights__table-wrap"><table><thead><tr><th>مشتری</th><th>تماس</th><th>نوع</th><th>تعداد سفارش</th><th>مجموع خرید</th><th>میانگین سبد</th><th>اولین خرید</th><th>آخرین خرید</th><th>کانال‌ها</th></tr></thead><tbody>${rows.map(row => `
    <tr>
      <td><strong>${esc(row.name || 'بدون نام')}</strong><small>${row.customer_id ? `Woo #${esc(row.customer_id)}` : 'مهمان قدیمی'}</small></td>
      <td><strong>${esc(row.phone || '—')}</strong><small>${esc(row.email || '')}</small></td>
      <td>${badge(row.customer_id ? 'ووکامرس' : 'مهمان')}</td>
      <td>${num(row.orders)}</td>
      <td><strong>${toman(row.total_spent_irr)}</strong></td>
      <td>${toman(row.average_order_irr)}</td>
      <td>${esc(row.first_order_at || '—')}</td>
      <td>${esc(row.last_order_at || '—')}</td>
      <td>${(row.channels || []).map(channel => `<span class="rishe-insights__pill">${esc(channelLabel(channel))}</span>`).join('')}</td>
    </tr>`).join('')}</tbody></table></div>`;
}

function exportCustomers() {
  exportCsv(`rishe-customers-${new Date().toISOString().slice(0, 10)}.csv`, [
    'نام', 'موبایل', 'ایمیل', 'شناسه ووکامرس', 'تعداد سفارش', 'مجموع خرید (ریال)',
    'میانگین سبد (ریال)', 'اولین خرید', 'آخرین خرید', 'کانال‌ها'
  ], lastCustomerRows.map(row => [
    row.name, row.phone, row.email, row.customer_id || '', row.orders, row.total_spent_irr,
    row.average_order_irr, row.first_order_at, row.last_order_at, (row.channels || []).map(channelLabel).join(' | ')
  ]));
}

function renderActive() {
  if (activeTab === 'customers') return renderCustomers();
  return renderSales();
}

root.querySelectorAll('[data-tab]').forEach(button => {
  button.addEventListener('click', () => {
    root.querySelectorAll('[data-tab]').forEach(item => item.classList.remove('is-active'));
    button.classList.add('is-active');
    activeTab = button.dataset.tab;
    customerPage = 1;
    renderActive();
  });
});
root.querySelector('[data-refresh]').addEventListener('click', renderActive);

renderActive();
})();
