(() => {
  const app = document.getElementById('rishe-analytics-app');
  if (!app || !window.wp?.apiFetch || !window.risheAnalytics) return;
  wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(risheAnalytics.nonce));
  let dashboard = 'executive';
  const role = (name) => app.querySelector(`[data-role="${name}"]`);
  const formatNumber = (value) => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
  const formatMoney = (value) => `${formatNumber(value)} ریال`;
  const filters = () => Object.fromEntries(new FormData(role('filters')).entries());
  const query = () => new URLSearchParams(Object.entries(filters()).filter(([, value]) => value)).toString();
  const request = (path) => wp.apiFetch({ path: `${risheAnalytics.root}${path}` });
  const error = (message = '') => {
    const box = role('error'); box.classList.toggle('hidden', !message); box.querySelector('p').textContent = message;
  };
  const card = (label, value, money = false) => `<article class="rishe-analytics__card"><span>${label}</span><strong>${money ? formatMoney(value) : formatNumber(value)}</strong></article>`;
  const renderCards = (data) => {
    const maps = {
      executive: [['فروش امروز','today_revenue_irr',1],['فروش هفته','week_revenue_irr',1],['فروش ماه','month_revenue_irr',1],['سود ناخالص','gross_profit_irr',1],['تعداد سفارش','order_count',0],['میانگین سفارش','average_order_value_irr',1]],
      sales: [['درآمد','revenue_irr',1],['سود ناخالص','gross_profit_irr',1],['سفارش','order_count',0],['تعداد فروش','sales_qty_scaled',0],['تخفیف','discount_irr',1],['میانگین سفارش','average_order_value_irr',1]],
      inventory: [['موجودی','inventory_scaled',0],['فروش روز','sales_qty_scaled',0],['کم‌موجود','low_stock_count',0],['راکد','stagnant_count',0],['گردش (bp)','turnover_basis_points',0],['تاریخ Snapshot','snapshot_date',0]],
      finance: [['درآمد','revenue_irr',1],['بهای تمام‌شده','cogs_irr',1],['سود ناخالص','gross_profit_irr',1],['تخفیف','discount_irr',1],['حاشیه سود (bp)','margin_basis_points',0]],
      customers: [['مشتری جدید','new_customers',0],['مشتری فعال','active_customers',0],['مشتری تکراری','repeat_customers',0],['Repeat Rate (bp)','repeat_rate_basis_points',0],['AOV','average_order_value_irr',1],['Frequency (bp)','purchase_frequency_basis_points',0]],
    };
    role('cards').innerHTML = (maps[dashboard] || []).map(([label,key,money]) => card(label, data[key], Boolean(money))).join('');
  };
  const bars = (rows, valueKey = 'revenue_irr') => {
    if (!rows?.length) return '<p>داده‌ای وجود ندارد.</p>';
    const max = Math.max(...rows.map((row) => Math.abs(Number(row[valueKey] || 0))), 1);
    return rows.map((row) => `<div class="rishe-analytics__bar"><strong>${row.label || row.sku || '—'}</strong><div class="rishe-analytics__bar-track"><div class="rishe-analytics__bar-fill" style="width:${Math.min(100, Math.abs(Number(row[valueKey] || 0)) / max * 100)}%"></div></div><span>${formatNumber(row[valueKey])}</span></div>`).join('');
  };
  const table = (rows, columns) => {
    if (!rows?.length) return '<p>داده‌ای وجود ندارد.</p>';
    return `<table class="rishe-analytics__table"><thead><tr>${columns.map(([label]) => `<th>${label}</th>`).join('')}</tr></thead><tbody>${rows.slice(0,50).map((row) => `<tr>${columns.map(([,key,money]) => `<td>${money ? formatMoney(row[key]) : formatNumber(row[key] ?? row[key] === 0 ? row[key] : row[key] || '—')}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  };
  const renderReport = (data) => {
    const title = role('report-title');
    if (dashboard === 'sales') { title.textContent='فروش بر اساس کانال و گروه محصول'; role('report').innerHTML=`<h3>کانال‌ها</h3>${bars(data.by_channel)}<h3>گروه محصول</h3>${bars(data.by_product_line)}`; return; }
    if (dashboard === 'inventory') { title.textContent='موجودی، کم‌موجود و کالای راکد'; role('report').innerHTML=table(data.rows,[['SKU','sku'],['موجودی','inventory_scaled'],['فروش','sales_qty_scaled'],['حداقل','minimum_stock_scaled'],['درآمد','revenue_irr',1]]); return; }
    if (dashboard === 'finance') { title.textContent='روند مالی'; role('report').innerHTML=table(data.trend,[['تاریخ','fact_date'],['درآمد','revenue_irr',1],['COGS','cogs_irr',1],['سود','gross_profit_irr',1]]); return; }
    if (dashboard === 'customers') { title.textContent='رفتار و تکرار خرید مشتری'; role('report').innerHTML='<p>Dimension مشتری از Eventها ساخته می‌شود و First/Last Purchase، Source و جغرافیا را مستقل از جداول عملیاتی نگه می‌دارد.</p>'; return; }
    title.textContent='نمای اجرایی'; role('report').innerHTML='<p>نمای یکپارچه فروش، سود، سفارش، Target و Alert بر پایه Event Store و Projection تحلیلی.</p>';
  };
  const renderTargets = (targets=[]) => {
    role('targets').innerHTML = targets.length ? targets.map((target) => { const pct=Math.min(100,Number(target.achievement_basis_points || 0)/100); return `<div class="rishe-analytics__target"><div class="rishe-analytics__target-head"><strong>${target.kpi}</strong><span>${formatNumber(target.actual_value)} / ${formatNumber(target.target_value)}</span></div><div class="rishe-analytics__target-track"><div class="rishe-analytics__target-fill" style="width:${pct}%"></div></div></div>`; }).join('') : '<p>Target فعالی تعریف نشده است.</p>';
  };
  const renderAlerts = (alerts=[]) => {
    role('alerts').innerHTML = alerts.length ? alerts.slice(0,12).map((alert) => `<article class="rishe-analytics__alert rishe-analytics__alert--${alert.severity}"><h3>${alert.title}</h3><p>${alert.description}</p></article>`).join('') : '<p>هشدار بازی وجود ندارد.</p>';
  };
  const load = async () => {
    error('');
    try {
      const suffix=query();
      const [data, alerts] = await Promise.all([request(`/dashboard/${dashboard}${suffix ? `?${suffix}` : ''}`), request('/alerts?status=open')]);
      renderCards(data); renderReport(data); renderTargets(data.targets || []); renderAlerts(alerts.rows || []);
    } catch (e) { error(e.message || 'خطا در دریافت گزارش.'); }
  };
  role('filters').addEventListener('submit',(event)=>{event.preventDefault();load();});
  app.addEventListener('click',(event)=>{const tab=event.target.closest('[data-dashboard]');if(tab){dashboard=tab.dataset.dashboard;app.querySelectorAll('[data-dashboard]').forEach((item)=>item.classList.toggle('is-active',item===tab));load();}if(event.target.closest('[data-action="refresh"]'))load();});
  load();
})();
