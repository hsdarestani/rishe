(() => {
    'use strict';

    document.documentElement.lang = 'fa';
    document.documentElement.dir = 'rtl';

    const exact = {
        'Rishe ERP': 'سامانه ریشه',
        'Rishe Analytics': 'تحلیل‌های سامانه ریشه',
        'Rishe ERP workspace': 'محیط کاری سامانه ریشه',
        'Operations control center': 'مرکز کنترل عملیات',
        'Monitor integrations, retry failed work, inspect diagnostics, and move safe configuration between environments.': 'اتصال‌ها، کارهای پس‌زمینه، خطاها و سلامت سامانه را از یک صفحه مدیریت کنید.',
        'Refresh': 'تازه‌سازی',
        'Background execution': 'پردازش پس‌زمینه',
        'Operation jobs': 'کارهای پس‌زمینه',
        'Job type': 'نوع کار',
        'Invoice / shipment ID': 'شناسه صورتحساب یا مرسوله',
        'Idempotency key': 'کلید جلوگیری از ثبت تکراری',
        'Queue job': 'افزودن به صف',
        'ID': 'شناسه',
        'Type': 'نوع',
        'Reference': 'مرجع',
        'Status': 'وضعیت',
        'Attempts': 'تعداد تلاش',
        'Scheduled': 'زمان‌بندی',
        'Actions': 'عملیات',
        'Loading…': 'در حال دریافت…',
        'System health': 'سلامت سامانه',
        'Diagnostics': 'بررسی سلامت',
        'Action required': 'نیازمند رسیدگی',
        'Open incidents': 'رخدادهای باز',
        'Safe portability': 'جابه‌جایی امن تنظیمات',
        'Configuration package': 'بسته تنظیمات',
        'Exports only allowlisted non-secret settings. Imports require preview and checksum confirmation.': 'فقط تنظیمات غیرمحرمانه مجاز صادر می‌شوند و ورود تنظیمات پس از پیش‌نمایش و تأیید انجام می‌شود.',
        'Export JSON': 'دریافت فایل تنظیمات',
        'Choose import file': 'انتخاب فایل تنظیمات',
        'Immutable trail': 'سابقه غیرقابل‌تغییر',
        'Recent audit events': 'آخرین رویدادهای نظارتی',
        'Time': 'زمان',
        'Event': 'رویداد',
        'Aggregate': 'رکورد مرتبط',
        'Actor': 'انجام‌دهنده',
        'Correlation': 'شناسه پیگیری',
        'Executive intelligence': 'هوش مدیریتی',
        'Event-driven KPIs, targets, attribution, inventory snapshots, and actionable alerts.': 'شاخص‌های کلیدی، اهداف، منابع فروش، تصویر موجودی و هشدارهای قابل‌اقدام.',
        'From': 'از تاریخ',
        'To': 'تا تاریخ',
        'Channel': 'کانال فروش',
        'Product line': 'گروه محصول',
        'Apply': 'اعمال فیلتر',
        'Executive': 'مدیریتی',
        'Sales': 'فروش',
        'Inventory': 'موجودی',
        'Finance': 'مالی',
        'Customers': 'مشتریان',
        'Executive overview': 'نمای مدیریتی',
        'Targets': 'اهداف',
        'Executive alerts': 'هشدارهای مدیریتی',
        'No operation jobs yet.': 'هنوز کاری در صف عملیات ثبت نشده است.',
        'No diagnostics available.': 'اطلاعات بررسی سلامت در دسترس نیست.',
        'No open incidents.': 'رخداد بازی وجود ندارد.',
        'No audit events.': 'رویداد نظارتی ثبت نشده است.',
        'Pending jobs': 'کارهای در انتظار',
        'Running jobs': 'کارهای در حال اجرا',
        'Failed jobs': 'کارهای ناموفق',
        'Rejected invoices': 'صورتحساب‌های ردشده',
        'Delivery exceptions': 'مشکلات ارسال',
        'Retry': 'تلاش مجدد',
        'Cancel': 'لغو',
        'Acknowledge': 'بررسی شد',
        'Resolve': 'حل شد',
        'Operation job was queued.': 'کار با موفقیت به صف افزوده شد.',
        'Incident status was updated.': 'وضعیت رخداد به‌روزرسانی شد.',
        'Safe configuration package was exported.': 'فایل امن تنظیمات دریافت شد.',
        'Selected configuration file is not valid JSON.': 'فایل انتخاب‌شده، فایل تنظیمات معتبر نیست.',
        'Apply confirmed package': 'اعمال تغییرهای تأییدشده',
        'Apply the previewed non-secret configuration changes?': 'تغییرهای غیرمحرمانه نمایش‌داده‌شده اعمال شوند؟',
        'Configuration package was imported.': 'بسته تنظیمات با موفقیت وارد شد.',
        'Unexpected request failure.': 'در ارتباط با سرور خطایی رخ داد.',
        'Unknown Rishe ERP module.': 'ماژول درخواستی سامانه ریشه پیدا نشد.',
        'You do not have permission to access this Rishe ERP module.': 'شما اجازه دسترسی به این بخش از سامانه ریشه را ندارید.',
        'You do not have permission to manage Rishe operations.': 'شما اجازه مدیریت عملیات سامانه ریشه را ندارید.',
        'You do not have permission to view Rishe analytics.': 'شما اجازه مشاهده گزارش‌های سامانه ریشه را ندارید.',
        'Module sections': 'بخش‌های ماژول',
        'Close': 'بستن',
        'website / pos': 'وب‌سایت / فروش حضوری',
        'Target فعالی تعریف نشده است.': 'هدف فعالی تعریف نشده است.',
        'Repeat Rate (bp)': 'نرخ خرید مجدد (در ده‌هزار)',
        'AOV': 'میانگین مبلغ سفارش',
        'Frequency (bp)': 'تکرار خرید (در ده‌هزار)',
        'COGS': 'بهای تمام‌شده',
        'SKU': 'شناسه کالا',
        'Snapshot': 'تصویر موجودی',
        'Provider': 'ارائه‌دهنده سرویس',
        'Providers': 'ارائه‌دهندگان سرویس',
        'Credentials': 'اطلاعات دسترسی',
        'Webhook Secret': 'کلید محرمانه وب‌هوک',
        'Base URL': 'نشانی پایه سرویس',
        'COD': 'پرداخت در محل',
        'Hash': 'اثر انگشت داده',
        'Schema': 'ساختار پایگاه‌داده',
        'Database': 'پایگاه‌داده',
        'PHP': 'پی‌اچ‌پی',
        'OpenSSL': 'رمزنگاری امن',
        'HTTPS': 'اتصال امن سایت',
        'B2B': 'فروش سازمانی',
        'CRM': 'ارتباط با مشتریان',
        'BOM': 'فرمول ساخت',
        'JSON': 'فایل تنظیمات',
        'system': 'سامانه',
        'unknown': 'نامشخص',
        'connected': 'متصل',
        'loaded': 'فعال',
        'missing': 'موجود نیست',
        'present': 'موجود',
        'configured': 'تنظیم‌شده',
        'enabled': 'فعال',
        'disabled': 'غیرفعال',
        'active': 'فعال',
        'inactive': 'غیرفعال',
        'draft': 'پیش‌نویس',
        'approved': 'تأییدشده',
        'posted': 'قطعی‌شده',
        'pending': 'در انتظار',
        'running': 'در حال اجرا',
        'processing': 'در حال پردازش',
        'retry_wait': 'در انتظار تلاش مجدد',
        'completed': 'تکمیل‌شده',
        'failed': 'ناموفق',
        'cancelled': 'لغوشده',
        'rejected': 'ردشده',
        'open': 'باز',
        'acknowledged': 'بررسی‌شده',
        'resolved': 'حل‌شده',
        'paid': 'پرداخت‌شده',
        'unpaid': 'پرداخت‌نشده',
        'partially_paid': 'بخشی پرداخت‌شده',
        'shipped': 'ارسال‌شده',
        'delivered': 'تحویل‌شده',
        'returned': 'مرجوع‌شده',
        'critical': 'بحرانی',
        'warning': 'هشدار',
        'info': 'اطلاع‌رسانی',
        'ok': 'سالم',
        'production': 'عملیاتی',
        'sandbox': 'آزمایشی',
        'fifo': 'اولین‌وارده، اولین‌صادره',
        'lifo': 'آخرین‌وارده، اولین‌صادره',
        'debit': 'بدهکار',
        'credit': 'بستانکار',
        'action_scheduler': 'زمان‌بند ووکامرس',
        'wp_cron': 'زمان‌بند وردپرس',
        'tax.submit': 'ارسال صورتحساب به سامانه مؤدیان',
        'tax.inquire': 'استعلام وضعیت صورتحساب',
        'logistics.tracking.refresh': 'تازه‌سازی رهگیری مرسوله',
        'system.noop': 'آزمون سلامت پردازشگر'
    };

    const keys = {
        id: 'شناسه', code: 'کد', name: 'نام', status: 'وضعیت', type: 'نوع', key: 'کلید', value: 'مقدار',
        created_at: 'زمان ایجاد', updated_at: 'زمان ویرایش', occurred_at: 'زمان رخداد', scheduled_at: 'زمان‌بندی',
        product_id: 'شناسه کالا', warehouse_id: 'شناسه انبار', customer_id: 'شناسه مشتری',
        sales_order_id: 'شناسه سفارش فروش', purchase_order_id: 'شناسه سفارش خرید', shipment_id: 'شناسه مرسوله',
        amount_irr: 'مبلغ', total_irr: 'جمع مبلغ', unit_price_irr: 'قیمت واحد', quantity: 'مقدار',
        reference: 'مرجع', description: 'شرح', notes: 'یادداشت', mobile: 'موبایل', email: 'ایمیل', sku: 'شناسه کالا',
        event_type: 'نوع رویداد', aggregate_type: 'نوع رکورد', aggregate_id: 'شناسه رکورد', correlation_id: 'شناسه پیگیری',
        actor_user_id: 'کاربر انجام‌دهنده', attempts: 'تعداد تلاش', max_attempts: 'حداکثر تلاش',
        plugin_version: 'نسخه افزونه', database_version: 'نسخه ساختار پایگاه‌داده', database_connection: 'اتصال پایگاه‌داده',
        database_server: 'سرور پایگاه‌داده', php_version: 'نسخه پی‌اچ‌پی', wordpress_version: 'نسخه وردپرس',
        woocommerce_active: 'ووکامرس فعال', openssl: 'رمزنگاری امن', https: 'اتصال امن سایت', scheduler: 'زمان‌بند',
        auth_salts: 'کلیدهای امنیتی وردپرس', timestamp: 'زمان بررسی', message: 'پیام', error: 'خطا',
        first_name: 'نام', last_name: 'نام خانوادگی', fiscal_year: 'سال مالی', voucher_date: 'تاریخ سند',
        batch_code: 'کد بچ', expiry_date: 'تاریخ انقضا', received_at: 'زمان دریافت', unit_cost_irr: 'بهای واحد',
        invoice_number: 'شماره صورتحساب', tax_id: 'شناسه مالیاتی', tracking_code: 'کد رهگیری'
    };

    const faDigits = (value) => String(value).replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);

    const translatePhrase = (raw) => {
        const value = String(raw ?? '');
        const trimmed = value.trim();
        if (!trimmed) return value;
        if (exact[trimmed]) return value.replace(trimmed, exact[trimmed]);
        if (keys[trimmed]) return value.replace(trimmed, keys[trimmed]);
        if (/^table\./.test(trimmed)) return value.replace(trimmed, `جدول ${trimmed.slice(6).replaceAll('_', ' ')}`);
        if (/not found/i.test(trimmed)) return 'رکورد درخواستی پیدا نشد.';
        if (/permission|forbidden|unauthorized/i.test(trimmed)) return 'اجازه انجام این عملیات را ندارید.';
        if (/invalid|required|must be/i.test(trimmed)) return 'اطلاعات واردشده معتبر نیست. فیلدهای الزامی و مقادیر را بررسی کنید.';
        if (/conflict|already exists|duplicate/i.test(trimmed)) return 'این عملیات تکراری است یا با وضعیت فعلی رکورد سازگار نیست.';
        if (/insufficient/i.test(trimmed)) return 'موجودی یا اعتبار کافی نیست.';
        if (/unexpected .* error/i.test(trimmed)) return 'در انجام عملیات خطای پیش‌بینی‌نشده‌ای رخ داد.';
        if (/^[a-z][a-z0-9_.-]*$/i.test(trimmed)) {
            const parts = trimmed.split(/[_.-]+/);
            const words = parts.map((part) => exact[part] || keys[part] || part);
            if (words.some((word, index) => word !== parts[index])) return words.join(' ');
        }
        let translated = trimmed;
        Object.entries(exact).forEach(([source, target]) => {
            if (source.length > 2 && translated.includes(source)) translated = translated.replaceAll(source, target);
        });
        return value.replace(trimmed, faDigits(translated));
    };

    const localizeObject = (value) => {
        if (Array.isArray(value)) return value.map(localizeObject);
        if (value && typeof value === 'object') {
            return Object.fromEntries(Object.entries(value).map(([key, item]) => [keys[key] || exact[key] || key, localizeObject(item)]));
        }
        if (typeof value === 'boolean') return value ? 'بله' : 'خیر';
        return translatePhrase(value);
    };

    const translateTextNode = (node) => {
        const parent = node.parentElement;
        if (!parent || parent.closest('script,style,noscript,[data-rishe-no-translate]')) return;
        if (parent.matches('input,textarea')) return;
        const translated = translatePhrase(node.nodeValue);
        if (translated !== node.nodeValue) node.nodeValue = translated;
    };

    const translateJson = (element) => {
        if (!element.matches('pre.rishe-admin__json-view')) return false;
        try {
            const parsed = JSON.parse(element.textContent);
            element.textContent = JSON.stringify(localizeObject(parsed), null, 2);
            return true;
        } catch (error) {
            return false;
        }
    };

    const translateElement = (element) => {
        if (!(element instanceof Element)) return;
        if (translateJson(element)) return;
        ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
            if (element.hasAttribute(attribute)) element.setAttribute(attribute, translatePhrase(element.getAttribute(attribute)));
        });
        Array.from(element.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE).forEach(translateTextNode);
        element.querySelectorAll('*').forEach((child) => {
            if (translateJson(child)) return;
            ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
                if (child.hasAttribute(attribute)) child.setAttribute(attribute, translatePhrase(child.getAttribute(attribute)));
            });
            Array.from(child.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE).forEach(translateTextNode);
        });
    };

    const page = new URLSearchParams(window.location.search).get('page') || '';
    if (page !== 'rishe' && !page.startsWith('rishe-')) return;

    translateElement(document.body);
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
                if (node.nodeType === Node.ELEMENT_NODE) translateElement(node);
            });
            if (mutation.type === 'characterData') translateTextNode(mutation.target);
        });
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
})();
