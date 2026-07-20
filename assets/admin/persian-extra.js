(() => {
    'use strict';

    const page = new URLSearchParams(window.location.search).get('page') || '';
    if (page !== 'rishe' && !page.startsWith('rishe-')) return;

    const exact = {
        'Providerها': 'ارائه‌دهندگان سرویس',
        'ارائه‌دهنده سرویسها': 'ارائه‌دهندگان سرویس',
        'Provider پرداخت': 'ارائه‌دهنده پرداخت',
        'ارائه‌دهنده سرویس پرداخت': 'ارائه‌دهنده پرداخت',
        'Provider': 'ارائه‌دهنده سرویس',
        'Adapter': 'رابط اتصال',
        'Callback': 'نشانی بازگشت پرداخت',
        'Hash': 'اثر انگشت داده',
        'Credentialها': 'اطلاعات دسترسی',
        'Credentials': 'اطلاعات دسترسی',
        'Webhook Secret': 'کلید محرمانه وب‌هوک',
        'Base URL': 'نشانی پایه سرویس',
        'COD': 'پرداخت در محل',
        'BOMها': 'فرمول‌های ساخت',
        'فرمول ساختها': 'فرمول‌های ساخت',
        'BOM جدید': 'فرمول ساخت جدید',
        'شناسه BOM': 'شناسه فرمول ساخت',
        'BOM': 'فرمول ساخت',
        'ضایعات bp': 'نرخ ضایعات (از ده‌هزار)',
        'کمیسیون bp': 'نرخ کمیسیون (از ده‌هزار)',
        'VAT bp': 'نرخ مالیات بر ارزش افزوده (از ده‌هزار)',
        'کلید خصوصی PEM': 'کلید خصوصی با قالب PEM',
        'گواهی PEM': 'گواهی با قالب PEM',
        'Key ID': 'شناسه کلید',
        'Nonce': 'توکن امنیتی',
        'Capability': 'سطح دسترسی',
        'Nonce و Capability وردپرس': 'توکن امنیتی و سطح دسترسی وردپرس',
        'نسخه Schema': 'نسخه ساختار پایگاه‌داده',
        'PHP': 'پی‌اچ‌پی',
        'Database': 'پایگاه‌داده',
        'Schema': 'ساختار پایگاه‌داده',
        'API': 'رابط برنامه‌نویسی',
        'SKU': 'شناسه کالا',
        'IRR': 'ریال ایران',
        'manual': 'دستی',
        'central': 'مرکزی',
        'branch': 'شعبه',
        'workbench': 'کارگاه',
        'consignment': 'امانی',
        'other': 'سایر',
        'raw_material': 'مواد اولیه',
        'packaging': 'بسته‌بندی',
        'fixed': 'ثابت',
        'percent': 'درصد',
        'bank': 'بانک',
        'cash': 'صندوق',
        'gateway': 'درگاه',
        'wallet': 'کیف پول',
        'pos': 'کارت‌خوان',
        'in': 'ورودی',
        'out': 'خروجی',
        'agent': 'عامل فروش',
        'wholesale': 'عمده‌فروش',
        'consignee': 'امانی',
        'legal': 'حقوقی',
        'natural': 'حقیقی',
        'consumer': 'مصرف‌کننده',
        'mixed': 'ترکیبی',
        'freight': 'کرایه حمل',
        'surcharge': 'هزینه اضافی',
        'insurance': 'بیمه',
        'cod': 'پرداخت در محل',
        'return': 'مرجوعی',
        'adjustment': 'تعدیل',
        'website': 'وب‌سایت',
        'instagram': 'اینستاگرام',
        'telegram': 'تلگرام',
        'sms': 'پیامک',
        'phone': 'تلفنی',
        'referral': 'معرفی',
        'direct': 'مستقیم'
    };

    const words = {
        id: 'شناسه', code: 'کد', key: 'کلید', value: 'مقدار', name: 'نام', type: 'نوع', status: 'وضعیت',
        external: 'خارجی', customer: 'مشتری', product: 'کالا', warehouse: 'انبار', sales: 'فروش', order: 'سفارش',
        purchase: 'خرید', shipment: 'مرسوله', amount: 'مبلغ', total: 'جمع', unit: 'واحد', price: 'قیمت', quantity: 'مقدار',
        reference: 'مرجع', description: 'شرح', notes: 'یادداشت', mobile: 'موبایل', email: 'ایمیل', event: 'رویداد',
        aggregate: 'رکورد', correlation: 'پیگیری', actor: 'انجام‌دهنده', attempts: 'تلاش‌ها', max: 'حداکثر', plugin: 'افزونه',
        database: 'پایگاه‌داده', connection: 'اتصال', server: 'سرور', version: 'نسخه', wordpress: 'وردپرس',
        woocommerce: 'ووکامرس', active: 'فعال', scheduler: 'زمان‌بند', auth: 'امنیتی', salts: 'کلیدها', timestamp: 'زمان',
        message: 'پیام', error: 'خطا', first: 'نام', last: 'خانوادگی', fiscal: 'مالی', year: 'سال', voucher: 'سند', date: 'تاریخ',
        batch: 'بچ', expiry: 'انقضا', received: 'دریافت', cost: 'هزینه', invoice: 'صورتحساب', number: 'شماره', tax: 'مالیات',
        tracking: 'رهگیری', created: 'ایجاد', updated: 'ویرایش', occurred: 'رخداد', scheduled: 'زمان‌بندی', line: 'ردیف',
        discount: 'تخفیف', shipping: 'ارسال', loyalty: 'وفاداری', points: 'امتیاز', provider: 'ارائه‌دهنده', adapter: 'رابط',
        config: 'تنظیمات', secrets: 'اطلاعات محرمانه', callback: 'بازگشت', transaction: 'تراکنش', direction: 'جهت', source: 'منبع',
        gross: 'ناخالص', fee: 'کارمزد', net: 'خالص', settled: 'تسویه', supplier: 'تأمین‌کننده', national: 'ملی',
        economic: 'اقتصادی', payment: 'پرداخت', terms: 'شرایط', days: 'روز', credit: 'اعتبار', limit: 'سقف', payable: 'پرداختنی',
        floating: 'شناور', detail: 'تفصیل', expected: 'مورد انتظار', estimated: 'برآوردی', landed: 'جانبی', allocation: 'تسهیم',
        basis: 'مبنا', dispatch: 'ارسال', account: 'حساب', commission: 'کمیسیون', rate: 'نرخ', bps: 'از ده‌هزار',
        receivable: 'دریافتنی', destination: 'مقصد', returned: 'برگشت', reported: 'گزارش', carrier: 'شرکت حمل', mode: 'حالت',
        base: 'پایه', url: 'نشانی', credentials: 'اطلاعات دسترسی', webhook: 'وب‌هوک', declared: 'اظهارشده', sender: 'فرستنده',
        recipient: 'گیرنده', province: 'استان', city: 'شهر', address: 'نشانی', postal: 'پستی', packages: 'بسته‌ها', weight: 'وزن',
        grams: 'گرم', length: 'طول', width: 'عرض', height: 'ارتفاع', service: 'سرویس', quote: 'استعلام', incurred: 'تحقق',
        profile: 'پروفایل', taxpayer: 'مؤدی', memory: 'حافظه', default: 'پیش‌فرض', pattern: 'الگو', private: 'خصوصی',
        pem: 'قالب PEM', certificate: 'گواهی', measurement: 'اندازه‌گیری', vat: 'مالیات ارزش افزوده', buyer: 'خریدار',
        settlement: 'تسویه', method: 'روش', raw: 'خام', hash: 'اثر انگشت', reason: 'دلیل', channel: 'کانال', promotion: 'تخفیف',
        starts: 'شروع', ends: 'پایان', usage: 'استفاده', per: 'برای هر', inventory: 'موجودی', input: 'ورودی', output: 'خروجی',
        labor: 'دستمزد', overhead: 'سربار', component: 'جزء', waste: 'ضایعات', sequence: 'ترتیب', effective: 'اعتبار', from: 'از', to: 'تا'
    };

    const keyMap = {
        wc_product_id: 'شناسه محصول ووکامرس',
        external_customer_id: 'شناسه خارجی مشتری',
        line_discount_irr: 'تخفیف ردیف',
        idempotency_key: 'کلید جلوگیری از ثبت تکراری',
        correlation_id: 'شناسه پیگیری',
        fiscal_year: 'سال مالی',
        voucher_date: 'تاریخ سند',
        normal_balance: 'ماهیت حساب',
        requires_floating_detail: 'تفصیل اجباری',
        inventory_method: 'روش گردش موجودی',
        reference_type: 'نوع مرجع',
        reference_id: 'شناسه مرجع',
        expires_at: 'زمان انقضا',
        output_product_id: 'کالای خروجی',
        output_quantity: 'مقدار خروجی',
        input_warehouse_id: 'انبار مواد اولیه',
        output_warehouse_id: 'انبار محصول',
        labor_cost_irr: 'هزینه دستمزد',
        overhead_cost_irr: 'هزینه سربار',
        promotion_code: 'کد تخفیف',
        loyalty_points: 'امتیاز وفاداری',
        shipping_irr: 'هزینه ارسال',
        tax_irr: 'مالیات',
        external_payment_id: 'شناسه خارجی پرداخت',
        raw_hash: 'اثر انگشت داده',
        treasury_account_id: 'شناسه حساب خزانه',
        external_transaction_id: 'شناسه خارجی تراکنش',
        transaction_at: 'زمان تراکنش',
        value_date: 'تاریخ ارزش',
        counterparty_name: 'نام طرف مقابل',
        counterparty_iban: 'شبای طرف مقابل',
        external_settlement_id: 'شناسه خارجی تسویه',
        gross_amount_irr: 'مبلغ ناخالص',
        fee_amount_irr: 'کارمزد',
        net_amount_irr: 'مبلغ خالص',
        settled_at: 'زمان تسویه',
        payment_terms_days: 'مهلت پرداخت',
        credit_limit_irr: 'سقف اعتبار',
        expected_at: 'زمان تحویل مورد انتظار',
        estimated_landed_cost_irr: 'برآورد هزینه جانبی',
        landed_costs: 'هزینه‌های جانبی',
        allocation_basis: 'مبنای تسهیم',
        commission_rate_bps: 'نرخ کمیسیون از ده‌هزار',
        settlement_terms_days: 'مهلت تسویه',
        dispatched_at: 'زمان ارسال',
        returned_at: 'زمان برگشت',
        reported_at: 'زمان گزارش',
        base_url: 'نشانی پایه سرویس',
        webhook_secret: 'کلید محرمانه وب‌هوک',
        charged_shipping_irr: 'هزینه ارسال دریافت‌شده از مشتری',
        declared_value_irr: 'ارزش اظهارشده',
        cod_amount_irr: 'مبلغ پرداخت در محل',
        service_code: 'کد سرویس',
        quote_reference: 'مرجع استعلام',
        external_cost_id: 'شناسه خارجی هزینه',
        cost_type: 'نوع هزینه',
        incurred_at: 'زمان ثبت هزینه',
        invoice_reference: 'مرجع صورتحساب',
        taxpayer_type: 'نوع مؤدی',
        fiscal_memory_id: 'شناسه حافظه مالیاتی',
        branch_code: 'کد شعبه',
        default_invoice_type: 'نوع پیش‌فرض صورتحساب',
        default_pattern: 'الگوی پیش‌فرض',
        gateway_type: 'نوع اتصال',
        gateway_config: 'تنظیمات اتصال',
        private_key_pem: 'کلید خصوصی با قالب PEM',
        certificate_pem: 'گواهی با قالب PEM',
        key_id: 'شناسه کلید',
        tax_product_id: 'شناسه رسمی کالا یا خدمت',
        measurement_unit: 'واحد اندازه‌گیری',
        vat_rate_basis_points: 'نرخ مالیات بر ارزش افزوده از ده‌هزار',
        invoice_type: 'نوع صورتحساب',
        invoice_pattern: 'الگوی صورتحساب',
        settlement_method: 'روش تسویه',
        buyer_type: 'نوع خریدار'
    };

    const translateKey = (value) => {
        const raw = String(value ?? '').trim();
        if (!raw || /[\u0600-\u06ff]/.test(raw)) return raw;
        if (keyMap[raw]) return keyMap[raw];
        if (exact[raw]) return exact[raw];
        const parts = raw.toLowerCase().split(/[_.\-\s]+/).filter(Boolean);
        if (!parts.length) return raw;
        const converted = parts.map((part) => words[part] || exact[part] || part);
        const changed = converted.some((part, index) => part !== parts[index]);
        return changed ? converted.join(' ') : raw;
    };

    const translateText = (value) => {
        const raw = String(value ?? '');
        const trimmed = raw.trim();
        if (!trimmed) return raw;
        if (exact[trimmed]) return raw.replace(trimmed, exact[trimmed]);
        if (keyMap[trimmed]) return raw.replace(trimmed, keyMap[trimmed]);

        let translated = trimmed;
        Object.keys(exact).sort((a, b) => b.length - a.length).forEach((source) => {
            if (translated.includes(source)) translated = translated.replaceAll(source, exact[source]);
        });

        if (/^[a-z0-9_.\-\s]+$/i.test(translated)) translated = translateKey(translated);
        translated = translated
            .replace(/\bProvider\b/gi, 'ارائه‌دهنده سرویس')
            .replace(/\bAdapter\b/gi, 'رابط اتصال')
            .replace(/\bCallback\b/gi, 'نشانی بازگشت')
            .replace(/\bCredentials?\b/gi, 'اطلاعات دسترسی')
            .replace(/\bWebhook Secret\b/gi, 'کلید محرمانه وب‌هوک')
            .replace(/\bBase URL\b/gi, 'نشانی پایه سرویس')
            .replace(/\bKey ID\b/gi, 'شناسه کلید')
            .replace(/\bVAT\s*bp\b/gi, 'نرخ مالیات بر ارزش افزوده (از ده‌هزار)')
            .replace(/\bbp\b/gi, 'از ده‌هزار');

        return raw.replace(trimmed, translated);
    };

    const localizeObject = (value) => {
        if (Array.isArray(value)) return value.map(localizeObject);
        if (value && typeof value === 'object') {
            return Object.fromEntries(Object.entries(value).map(([key, item]) => [translateKey(key), localizeObject(item)]));
        }
        if (typeof value === 'boolean') return value ? 'بله' : 'خیر';
        if (typeof value === 'string') return translateText(value);
        return value;
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

    const translateTextNode = (node) => {
        const parent = node.parentElement;
        if (!parent || parent.closest('script,style,noscript,[data-rishe-no-translate]')) return;
        if (parent.matches('input,textarea,select,option')) return;
        const translated = translateText(node.nodeValue);
        if (translated !== node.nodeValue) node.nodeValue = translated;
    };

    const translateElement = (element) => {
        if (!(element instanceof Element)) return;
        if (translateJson(element)) return;
        ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
            if (element.hasAttribute(attribute)) element.setAttribute(attribute, translateText(element.getAttribute(attribute)));
        });
        Array.from(element.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE).forEach(translateTextNode);
        element.querySelectorAll('*').forEach((child) => {
            if (translateJson(child)) return;
            ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
                if (child.hasAttribute(attribute)) child.setAttribute(attribute, translateText(child.getAttribute(attribute)));
            });
            Array.from(child.childNodes).filter((node) => node.nodeType === Node.TEXT_NODE).forEach(translateTextNode);
        });
    };

    const run = () => translateElement(document.body);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run, { once: true });
    else run();

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
                if (node.nodeType === Node.ELEMENT_NODE) translateElement(node);
            });
            if (mutation.type === 'characterData') translateTextNode(mutation.target);
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true, characterData: true });
})();
