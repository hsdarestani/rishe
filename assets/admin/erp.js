(() => {
    'use strict';

    const boot = window.risheAdmin || {};
    const api = window.wp && window.wp.apiFetch;
    const app = document.getElementById('rishe-admin-app');
    if (!api || !app) return;
    api.use(api.createNonceMiddleware(boot.nonce));

    const root = boot.root || '/rishe/v1';
    const $ = (s, r = app) => r.querySelector(s);
    const $$ = (s, r = app) => Array.from(r.querySelectorAll(s));
    const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const today = new Date().toISOString().slice(0, 10);
    const key = (p = 'rishe') => `${p}-${Date.now()}-${Math.random().toString(16).slice(2)}`;

    const F = (name, label, type = 'text', options = {}) => ({name, label, type, ...options});
    const id = (n, l, o = {}) => F(n, l, 'number', {min: 1, step: 1, ...o});
    const money = (n, l, o = {}) => F(n, l, 'number', {min: 0, step: 1, suffix: 'ریال', ...o});
    const qty = (n = 'quantity', l = 'مقدار', o = {}) => F(n, l, 'number', {min: 0.0001, step: 0.0001, ...o});
    const text = (n, l, o = {}) => F(n, l, o.password ? 'password' : 'text', o);
    const date = (n, l, o = {}) => F(n, l, 'date', o);
    const datetime = (n, l, o = {}) => F(n, l, 'datetime-local', o);
    const select = (n, l, options, o = {}) => F(n, l, 'select', {options, ...o});
    const area = (n, l, o = {}) => F(n, l, 'textarea', {wide: true, ...o});
    const check = (n, l, o = {}) => F(n, l, 'checkbox', o);
    const rows = (n, l, fields, o = {}) => F(n, l, 'rows', {fields, wide: true, ...o});
    const group = (n, l, fields, o = {}) => F(n, l, 'group', {fields, wide: true, ...o});
    const kv = (n, l, o = {}) => rows(n, l, [text('key','کلید',{required:true}), text('value','مقدار',{required:true})], {object:true, ...o});
    const post = (title, path, fields, o = {}) => ({kind:'form', title, path, fields, ...o});
    const list = (title, path, filters = [], o = {}) => ({kind:'list', title, path, filters, response:'rows', wide:true, ...o});
    const detail = (title, path, fields, o = {}) => ({kind:'detail', title, path, fields, ...o});
    const section = (id, title, cards, description = '') => ({id, title, cards, description});

    const common = {
        idem: text('idempotency_key','کلید یکتایی',{required:true, default:() => key()}),
        corr: text('correlation_id','شناسه همبستگی'),
        fy: F('fiscal_year','سال مالی','number',{required:true, default:1405, min:1300, max:1600}),
    };
    const customer = [text('mobile','موبایل',{required:true}), text('first_name','نام',{required:true}), text('last_name','نام خانوادگی',{required:true}), text('email','ایمیل'), text('external_customer_id','شناسه خارجی')];
    const orderLines = [id('product_id','کالا',{required:true}), qty('quantity','مقدار',{required:true}), money('unit_price_irr','قیمت واحد',{required:true}), money('line_discount_irr','تخفیف',{default:0})];

    const modules = {
        accounting: [
            section('chart','کدینگ حساب‌ها',[
                list('درخت حساب‌ها','/accounting/chart',[],{response:null}),
                post('گروه حساب','/accounting/account-groups',[text('code','کد',{required:true}),text('name','نام',{required:true}),select('normal_balance','ماهیت',[['debit','بدهکار'],['credit','بستانکار']],{required:true})]),
                post('حساب کل','/accounting/general-ledgers',[id('account_group_id','گروه',{required:true}),text('code','کد',{required:true}),text('name','نام',{required:true}),select('normal_balance','ماهیت',[['debit','بدهکار'],['credit','بستانکار']],{required:true})]),
                post('حساب معین','/accounting/subsidiary-ledgers',[id('general_ledger_id','حساب کل',{required:true}),text('code','کد',{required:true}),text('name','نام',{required:true}),select('normal_balance','ماهیت',[['debit','بدهکار'],['credit','بستانکار']],{required:true}),check('requires_floating_detail','تفصیل اجباری')]),
                post('تفصیل شناور','/accounting/floating-details',[text('code','کد',{required:true}),text('name','نام',{required:true}),text('detail_type','نوع',{required:true}),text('external_reference','شناسه خارجی'),text('mobile','موبایل')]),
            ]),
            section('voucher','اسناد حسابداری',[
                post('سند جدید','/accounting/vouchers',[common.fy,date('voucher_date','تاریخ',{required:true,default:today}),text('description','شرح',{required:true,wide:true}),common.corr,rows('lines','آرتیکل‌ها',[id('subsidiary_ledger_id','معین',{required:true}),id('floating_detail_id','تفصیل'),money('debit','بدهکار',{default:0}),money('credit','بستانکار',{default:0}),text('description','شرح')],{min:2})],{wide:true}),
                post('قطعی‌کردن سند','/accounting/vouchers/:id/post',[id('id','شناسه سند',{required:true})],{pathFields:['id']}),
                post('برگشت سند','/accounting/vouchers/:id/reverse',[id('id','شناسه سند',{required:true}),common.fy,date('voucher_date','تاریخ',{required:true,default:today}),text('description','شرح',{required:true})],{pathFields:['id']}),
                list('تراز آزمایشی','/accounting/trial-balance',[date('from','از',{required:true}),date('to','تا',{required:true,default:today})]),
            ]),
        ],
        inventory: [
            section('master','اطلاعات پایه',[
                post('انبار جدید','/inventory/warehouses',[text('code','کد',{required:true}),text('name','نام',{required:true}),select('type','نوع',[['central','مرکزی'],['branch','شعبه'],['workbench','کارگاه'],['consignment','امانی'],['other','سایر']],{required:true})]),
                post('کالای جدید','/inventory/products',[text('sku','SKU',{required:true}),text('name','نام',{required:true}),text('base_unit','واحد',{required:true}),select('inventory_method','روش گردش',[['fifo','FIFO'],['lifo','LIFO']],{required:true,default:'fifo'}),id('wc_product_id','محصول ووکامرس')]),
            ]),
            section('ops','عملیات موجودی',[
                post('رسید انبار','/inventory/receipts',[id('product_id','کالا',{required:true}),id('warehouse_id','انبار',{required:true}),text('batch_code','کد بچ',{required:true}),qty('quantity','مقدار',{required:true}),money('unit_cost_irr','بهای واحد',{required:true}),datetime('received_at','زمان دریافت'),date('expiry_date','انقضا'),text('reference_type','نوع مرجع',{default:'purchase'}),text('reference_id','شناسه مرجع'),common.corr]),
                post('رزرو موجودی','/inventory/reservations',[id('product_id','کالا',{required:true}),id('warehouse_id','انبار',{required:true}),qty('quantity','مقدار',{required:true}),text('reference_type','نوع مرجع',{required:true}),text('reference_id','شناسه مرجع',{required:true}),datetime('expires_at','انقضای رزرو'),common.corr]),
                post('آزادسازی رزرو','/inventory/reservations/:id/release',[id('id','شناسه رزرو',{required:true})],{pathFields:['id']}),
                post('قطعی‌کردن رزرو','/inventory/reservations/:id/commit',[id('id','شناسه رزرو',{required:true})],{pathFields:['id']}),
                post('انتقال موجودی','/inventory/transfers',[id('product_id','کالا',{required:true}),id('from_warehouse_id','مبدأ',{required:true}),id('to_warehouse_id','مقصد',{required:true}),qty('quantity','مقدار',{required:true}),text('reference_type','نوع مرجع',{default:'transfer'}),text('reference_id','مرجع'),common.corr]),
            ]),
            section('reports','گزارش‌های انبار',[
                list('موجودی لحظه‌ای','/inventory/stock',[id('product_id','کالا'),id('warehouse_id','انبار')]),
                list('کاردکس','/inventory/ledger',[id('product_id','کالا'),id('warehouse_id','انبار'),date('from','از'),date('to','تا')]),
            ]),
        ],
        manufacturing: [
            section('boms','فرمول ساخت',[
                list('BOMها','/manufacturing/boms',[text('status','وضعیت'),id('output_product_id','محصول خروجی')]),
                post('BOM جدید','/manufacturing/boms',[text('code','کد',{required:true}),text('name','نام',{required:true}),F('version','نسخه','number',{min:1}),id('output_product_id','محصول خروجی',{required:true}),qty('output_quantity','مقدار خروجی',{required:true}),date('effective_from','شروع'),date('effective_to','پایان'),rows('components','اجزای مصرفی',[id('product_id','کالا',{required:true}),select('component_type','نوع',[['raw_material','مواد اولیه'],['packaging','بسته‌بندی']],{required:true}),qty('quantity','مقدار',{required:true}),F('waste_basis_points','ضایعات bp','number',{min:0,max:10000,default:0}),F('sequence','ترتیب','number',{min:1})],{min:1})],{wide:true}),
                post('فعال‌سازی BOM','/manufacturing/boms/:id/activate',[id('id','شناسه BOM',{required:true})],{pathFields:['id']}),
            ]),
            section('production','دستور تولید',[
                list('دستورها','/manufacturing/orders',[id('bom_id','BOM'),date('from','از'),date('to','تا')]),
                post('اجرای تولید','/manufacturing/orders/execute',[id('bom_id','BOM',{required:true}),id('input_warehouse_id','انبار مواد',{required:true}),id('output_warehouse_id','انبار محصول',{required:true}),qty('output_quantity','مقدار تولید',{required:true}),text('output_batch_code','کد بچ',{required:true}),date('output_expiry_date','انقضا'),money('labor_cost_irr','دستمزد',{default:0}),money('overhead_cost_irr','سربار',{default:0}),text('reference_type','نوع مرجع',{required:true,default:'production'}),text('reference_id','شناسه مرجع',{required:true}),common.corr],{wide:true}),
                detail('جزئیات دستور','/manufacturing/orders/:id',[id('id','شناسه دستور',{required:true})],{pathFields:['id']}),
            ]),
        ],
        sales: [
            section('crm','مشتریان',[
                post('ثبت/ویرایش مشتری','/crm/customers',customer),
                detail('مشاهده مشتری','/crm/customers/:id',[id('id','شناسه مشتری',{required:true})],{pathFields:['id']}),
            ]),
            section('catalog','قیمت و پروموشن',[
                post('قیمت کانال','/sales/channel-prices',[id('product_id','کالا',{required:true}),text('channel','کانال',{required:true}),money('unit_price_irr','قیمت',{required:true})]),
                post('پروموشن','/sales/promotions',[text('code','کد',{required:true}),text('name','نام',{required:true}),text('channel','کانال'),select('discount_type','نوع',[['fixed','ثابت'],['percent','درصد']],{required:true}),F('value','مقدار','number',{min:0,required:true}),money('min_order_irr','حداقل سفارش',{default:0}),money('max_discount_irr','حداکثر تخفیف'),datetime('starts_at','شروع',{required:true}),datetime('ends_at','پایان',{required:true}),F('usage_limit','سقف استفاده','number',{min:1}),F('per_customer_limit','سقف هر مشتری','number',{min:1})]),
            ]),
            section('orders','سفارش‌ها',[
                list('فهرست سفارش‌ها','/sales/orders',[text('status','وضعیت'),text('channel','کانال'),id('customer_id','مشتری'),date('from','از'),date('to','تا')]),
                post('سفارش جدید','/sales/orders',[text('channel','کانال',{required:true,default:'manual'}),id('warehouse_id','انبار',{required:true}),text('external_order_id','شناسه خارجی'),common.idem,text('promotion_code','کد تخفیف'),F('loyalty_points','امتیاز','number',{min:0,default:0}),money('shipping_irr','ارسال',{default:0}),money('tax_irr','مالیات',{default:0}),common.corr,group('customer','مشتری',customer),rows('lines','اقلام',orderLines,{min:1})],{wide:true}),
                detail('جزئیات سفارش','/sales/orders/:id',[id('id','شناسه سفارش',{required:true})],{pathFields:['id']}),
                post('ثبت پرداخت','/sales/orders/:id/payments',[id('id','سفارش',{required:true}),text('provider','روش/درگاه',{required:true}),text('external_payment_id','شناسه پرداخت',{required:true}),money('amount_irr','مبلغ',{required:true}),text('raw_hash','Hash')],{pathFields:['id']}),
                post('تکمیل سفارش','/sales/orders/:id/complete',[id('id','سفارش',{required:true})],{pathFields:['id']}),
                post('لغو سفارش','/sales/orders/:id/cancel',[id('id','سفارش',{required:true}),area('reason','دلیل',{required:true})],{pathFields:['id']}),
                post('تلاش مجدد حسابداری','/sales/orders/:id/accounting/retry',[id('id','سفارش',{required:true})],{pathFields:['id']}),
            ]),
        ],
        treasury: [
            section('accounts','حساب‌ها و درگاه‌ها',[
                list('حساب‌های خزانه','/treasury/accounts'),
                post('حساب خزانه','/treasury/accounts',[text('code','کد',{required:true}),text('name','نام',{required:true}),select('type','نوع',[['bank','بانک'],['cash','صندوق'],['gateway','درگاه'],['wallet','کیف پول']],{required:true}),text('bank_name','بانک'),text('account_number','شماره حساب'),text('card_number','شماره کارت'),text('iban','شبا'),text('currency','ارز',{required:true,default:'IRR'}),id('subsidiary_ledger_id','معین',{required:true}),id('floating_detail_id','تفصیل')]),
                list('Providerها','/treasury/providers'),
                post('Provider پرداخت','/treasury/providers',[text('code','کد',{required:true}),text('name','نام',{required:true}),text('provider','Provider',{required:true}),text('adapter','Adapter',{required:true}),kv('config','تنظیمات'),kv('secrets','اطلاعات محرمانه')]),
            ]),
            section('transactions','پرداخت و تراکنش',[
                list('لینک‌های پرداخت','/treasury/payment-links'),
                post('لینک پرداخت','/treasury/payment-links',[text('provider','Provider',{required:true}),money('amount_irr','مبلغ',{required:true}),text('reference_type','نوع مرجع',{required:true}),text('reference_id','مرجع',{required:true}),id('sales_order_id','سفارش'),id('customer_id','مشتری'),text('description','شرح'),text('callback_url','Callback',{required:true}),datetime('expires_at','انقضا'),common.idem,common.corr]),
                list('تراکنش‌ها','/treasury/transactions',[id('treasury_account_id','حساب'),text('direction','جهت'),text('source','منبع'),date('from','از'),date('to','تا')]),
                post('ورود تراکنش','/treasury/transactions/import',[id('treasury_account_id','حساب',{required:true}),text('external_transaction_id','شناسه تراکنش',{required:true}),select('direction','جهت',[['in','ورودی'],['out','خروجی']],{required:true}),money('amount_irr','مبلغ',{required:true}),datetime('transaction_at','زمان',{required:true}),date('value_date','تاریخ ارزش'),text('counterparty_name','طرف مقابل'),text('counterparty_iban','شبا'),text('reference','مرجع'),text('description','شرح'),text('source','منبع',{required:true}),text('raw_hash','Hash'),common.corr]),
                post('تطبیق تراکنش','/treasury/transactions/:id/matches',[id('id','تراکنش',{required:true}),text('match_type','نوع تطبیق',{required:true}),text('entity_id','شناسه موجودیت',{required:true})],{pathFields:['id']}),
                list('تسویه‌ها','/treasury/settlements'),
                post('ثبت تسویه','/treasury/settlements',[text('provider','Provider',{required:true}),text('external_settlement_id','شناسه تسویه',{required:true}),id('treasury_account_id','حساب',{required:true}),money('gross_amount_irr','ناخالص',{required:true}),money('fee_amount_irr','کارمزد',{required:true,default:0}),money('net_amount_irr','خالص',{required:true}),datetime('settled_at','زمان',{required:true}),text('raw_hash','Hash')]),
            ]),
        ],
        procurement: [
            section('suppliers','تأمین‌کنندگان',[
                list('تأمین‌کنندگان','/procurement/suppliers',[text('search','جست‌وجو')]),
                post('ثبت/ویرایش تأمین‌کننده','/procurement/suppliers',[id('id','شناسه برای ویرایش'),text('code','کد',{required:true}),text('name','نام',{required:true}),text('mobile','موبایل'),text('email','ایمیل'),text('national_id','شناسه ملی'),text('economic_code','کد اقتصادی'),text('tax_id','شناسه مالیاتی'),text('iban','شبا'),F('payment_terms_days','مهلت پرداخت','number',{min:0,default:0}),money('credit_limit_irr','سقف اعتبار',{default:0}),id('payable_subsidiary_ledger_id','معین پرداختنی',{required:true}),id('floating_detail_id','تفصیل',{required:true})]),
                detail('جزئیات تأمین‌کننده','/procurement/suppliers/:id',[id('id','شناسه',{required:true})],{pathFields:['id']}),
                list('گردش حساب','/procurement/suppliers/:id/statement',[id('id','تأمین‌کننده',{required:true}),date('from','از'),date('to','تا')],{pathFields:['id']}),
            ]),
            section('orders','سفارش خرید',[
                list('سفارش‌های خرید','/procurement/purchase-orders',[id('supplier_id','تأمین‌کننده'),text('status','وضعیت'),date('from','از'),date('to','تا')]),
                post('سفارش خرید جدید','/procurement/purchase-orders',[id('supplier_id','تأمین‌کننده',{required:true}),id('warehouse_id','انبار',{required:true}),common.fy,date('expected_at','تحویل مورد انتظار'),text('external_reference','مرجع خارجی'),money('estimated_landed_cost_irr','برآورد هزینه جانبی',{default:0}),area('notes','یادداشت'),common.idem,common.corr,rows('lines','اقلام',[id('product_id','کالا',{required:true}),qty('quantity','مقدار',{required:true}),money('unit_price_irr','قیمت',{required:true}),money('discount_irr','تخفیف',{default:0}),money('tax_irr','مالیات',{default:0})],{min:1})],{wide:true}),
                detail('جزئیات سفارش','/procurement/purchase-orders/:id',[id('id','سفارش',{required:true})],{pathFields:['id']}),
                post('تصویب سفارش','/procurement/purchase-orders/:id/approve',[id('id','سفارش',{required:true})],{pathFields:['id']}),
                post('لغو سفارش','/procurement/purchase-orders/:id/cancel',[id('id','سفارش',{required:true}),area('reason','دلیل',{required:true})],{pathFields:['id']}),
            ]),
            section('receipts','رسید و پرداخت',[
                list('رسیدهای خرید','/procurement/receipts',[id('purchase_order_id','سفارش'),date('from','از'),date('to','تا')]),
                post('ثبت رسید خرید','/procurement/purchase-orders/:id/receipts',[id('id','سفارش',{required:true}),datetime('received_at','زمان دریافت',{required:true}),text('reference','شماره رسید',{required:true}),area('notes','یادداشت'),common.idem,common.corr,rows('lines','اقلام دریافتی',[id('purchase_order_line_id','ردیف سفارش',{required:true}),qty('quantity','مقدار',{required:true}),text('batch_code','کد بچ',{required:true}),date('expiry_date','انقضا')],{min:1}),rows('landed_costs','هزینه‌های جانبی',[text('type','نوع',{required:true}),money('amount_irr','مبلغ',{required:true}),select('allocation_basis','مبنای تسهیم',[['value','ارزش'],['quantity','مقدار']],{required:true})])],{pathFields:['id'],wide:true}),
                post('پرداخت تأمین‌کننده','/procurement/purchase-orders/:id/payments',[id('id','سفارش',{required:true}),id('treasury_transaction_id','تراکنش خزانه',{required:true}),money('amount_irr','مبلغ',{required:true}),date('paid_at','تاریخ',{default:today}),text('reference','مرجع')],{pathFields:['id']}),
                detail('جزئیات رسید','/procurement/receipts/:id',[id('id','رسید',{required:true})],{pathFields:['id']}),
            ]),
        ],
        b2b: [
            section('accounts','حساب‌های تجاری',[
                list('حساب‌ها','/b2b/accounts',[text('account_type','نوع'),text('search','جست‌وجو')]),
                post('ثبت/ویرایش حساب','/b2b/accounts',[id('id','شناسه برای ویرایش'),text('code','کد',{required:true}),text('name','نام',{required:true}),select('account_type','نوع',[['agent','عامل'],['wholesale','عمده‌فروش'],['consignee','امانی']],{required:true}),id('customer_id','مشتری'),id('consignment_warehouse_id','انبار امانی'),money('credit_limit_irr','سقف اعتبار',{default:0}),F('commission_rate_bps','کمیسیون bp','number',{min:0,max:10000,default:0}),F('settlement_terms_days','مهلت تسویه','number',{min:0,default:0}),id('receivable_subsidiary_ledger_id','معین دریافتنی',{required:true}),id('floating_detail_id','تفصیل',{required:true})]),
                detail('جزئیات حساب','/b2b/accounts/:id',[id('id','حساب',{required:true})],{pathFields:['id']}),
                list('گردش حساب','/b2b/accounts/:id/statement',[id('id','حساب',{required:true}),date('from','از'),date('to','تا')],{pathFields:['id']}),
                post('تسویه حساب','/b2b/accounts/:id/settlements',[id('id','حساب',{required:true}),money('amount_irr','مبلغ',{required:true}),id('treasury_transaction_id','تراکنش خزانه',{required:true}),date('settled_at','تاریخ',{default:today}),text('reference','مرجع')],{pathFields:['id']}),
            ]),
            section('dispatches','ارسال امانی',[
                list('ارسال‌ها','/consignment/dispatches',[id('account_id','حساب'),text('status','وضعیت'),date('from','از'),date('to','تا')]),
                post('ارسال جدید','/consignment/dispatches',[id('account_id','حساب',{required:true}),id('source_warehouse_id','انبار مبدأ',{required:true}),common.fy,datetime('dispatched_at','زمان ارسال',{required:true}),text('reference','مرجع',{required:true}),area('notes','یادداشت'),common.idem,common.corr,rows('lines','اقلام',[id('product_id','کالا',{required:true}),qty('quantity','مقدار',{required:true})],{min:1})],{wide:true}),
                detail('جزئیات ارسال','/consignment/dispatches/:id',[id('id','ارسال',{required:true})],{pathFields:['id']}),
                post('مرجوعی امانی','/consignment/dispatches/:id/returns',[id('id','ارسال',{required:true}),id('destination_warehouse_id','انبار مقصد',{required:true}),datetime('returned_at','زمان برگشت',{required:true}),area('notes','یادداشت'),common.idem,common.corr,rows('lines','اقلام',[id('dispatch_line_id','ردیف ارسال',{required:true}),qty('quantity','مقدار',{required:true})],{min:1})],{pathFields:['id'],wide:true}),
            ]),
            section('reports','گزارش فروش عامل',[
                list('گزارش‌ها','/consignment/sales-reports',[id('account_id','حساب'),date('from','از'),date('to','تا')]),
                post('ثبت گزارش فروش','/consignment/sales-reports',[id('account_id','حساب',{required:true}),common.fy,datetime('reported_at','زمان گزارش',{required:true}),text('external_reference','مرجع خارجی'),area('notes','یادداشت'),common.idem,common.corr,rows('lines','اقلام',[id('product_id','کالا',{required:true}),qty('quantity','مقدار',{required:true}),money('unit_price_irr','قیمت',{required:true}),F('commission_rate_bps','کمیسیون bp','number',{min:0,max:10000})],{min:1})],{wide:true}),
                detail('جزئیات گزارش','/consignment/sales-reports/:id',[id('id','گزارش',{required:true})],{pathFields:['id']}),
            ]),
        ],
        logistics: [
            section('carriers','شرکت‌های حمل',[
                list('شرکت‌های حمل','/logistics/carriers'),
                post('ثبت/ویرایش شرکت حمل','/logistics/carriers',[id('id','شناسه برای ویرایش'),text('code','کد',{required:true}),text('name','نام',{required:true}),select('mode','حالت',[['sandbox','آزمایشی'],['production','عملیاتی']],{required:true}),text('base_url','Base URL'),id('shipping_expense_subsidiary_ledger_id','معین هزینه حمل',{required:true}),kv('config','تنظیمات'),kv('credentials','Credentialها'),text('webhook_secret','Webhook Secret',{password:true})]),
                detail('جزئیات شرکت حمل','/logistics/carriers/:id',[id('id','شرکت حمل',{required:true})],{pathFields:['id']}),
            ]),
            section('shipments','مرسوله‌ها',[
                list('مرسوله‌ها','/logistics/shipments',[text('status','وضعیت'),id('sales_order_id','سفارش'),date('from','از'),date('to','تا')]),
                post('مرسوله جدید','/logistics/shipments',[id('sales_order_id','سفارش',{required:true}),money('charged_shipping_irr','هزینه مشتری',{default:0}),money('declared_value_irr','ارزش اظهارشده',{default:0}),money('cod_amount_irr','COD',{default:0}),common.idem,common.corr,area('notes','یادداشت'),group('sender','فرستنده',[text('name','نام',{required:true}),text('mobile','موبایل',{required:true}),text('province','استان',{required:true}),text('city','شهر',{required:true}),text('address','نشانی',{required:true}),text('postal_code','کدپستی')]),group('recipient','گیرنده',[text('name','نام',{required:true}),text('mobile','موبایل',{required:true}),text('province','استان',{required:true}),text('city','شهر',{required:true}),text('address','نشانی',{required:true}),text('postal_code','کدپستی')]),rows('packages','بسته‌ها',[F('weight_grams','وزن گرم','number',{min:1,required:true}),F('length_cm','طول','number',{min:1}),F('width_cm','عرض','number',{min:1}),F('height_cm','ارتفاع','number',{min:1}),text('description','شرح')],{min:1})],{wide:true}),
                detail('جزئیات مرسوله','/logistics/shipments/:id',[id('id','مرسوله',{required:true})],{pathFields:['id']}),
                post('استعلام نرخ','/logistics/shipments/:id/quote',[id('id','مرسوله',{required:true}),id('carrier_id','شرکت حمل',{required:true}),text('service_code','سرویس')],{pathFields:['id']}),
                post('رزرو ارسال','/logistics/shipments/:id/book',[id('id','مرسوله',{required:true}),id('carrier_id','شرکت حمل',{required:true}),text('quote_reference','مرجع استعلام'),text('service_code','سرویس')],{pathFields:['id']}),
                post('تازه‌سازی رهگیری','/logistics/shipments/:id/tracking/refresh',[id('id','مرسوله',{required:true})],{pathFields:['id']}),
                post('لغو مرسوله','/logistics/shipments/:id/cancel',[id('id','مرسوله',{required:true}),area('reason','دلیل',{required:true})],{pathFields:['id']}),
            ]),
            section('costs','هزینه و تسویه',[
                post('ثبت هزینه حمل','/logistics/shipments/:id/costs',[id('id','مرسوله',{required:true}),text('external_cost_id','شناسه هزینه',{required:true}),text('cost_type','نوع',{required:true}),money('amount_irr','مبلغ',{required:true}),datetime('incurred_at','زمان',{required:true}),text('invoice_reference','فاکتور'),text('description','شرح'),text('raw_hash','Hash')],{pathFields:['id']}),
                post('تسویه هزینه حمل','/logistics/shipments/:id/settlements',[id('id','مرسوله',{required:true}),id('treasury_transaction_id','تراکنش خزانه',{required:true}),money('amount_irr','مبلغ',{required:true}),date('settled_at','تاریخ',{default:today}),text('reference','مرجع')],{pathFields:['id']}),
            ]),
        ],
        tax: [
            section('profiles','پروفایل مالیاتی',[
                list('پروفایل‌ها','/tax/profiles'),
                post('ثبت/ویرایش پروفایل','/tax/profiles',[id('id','شناسه برای ویرایش'),text('code','کد',{required:true}),text('name','نام',{required:true}),select('taxpayer_type','نوع مؤدی',[['legal','حقوقی'],['natural','حقیقی']],{required:true}),text('national_id','شناسه ملی',{required:true}),text('economic_code','کد اقتصادی'),text('fiscal_memory_id','حافظه مالیاتی',{required:true}),text('branch_code','کد شعبه'),F('default_invoice_type','نوع پیش‌فرض','number',{min:1,default:1}),F('default_pattern','الگوی پیش‌فرض','number',{min:1,default:1}),text('gateway_type','نوع اتصال',{required:true}),kv('gateway_config','تنظیمات اتصال'),kv('credentials','Credentialها'),area('private_key_pem','کلید خصوصی PEM'),area('certificate_pem','گواهی PEM'),text('key_id','Key ID'),area('description','توضیحات')],{wide:true}),
                detail('جزئیات پروفایل','/tax/profiles/:id',[id('id','پروفایل',{required:true})],{pathFields:['id']}),
            ]),
            section('mappings','نگاشت کالا',[
                list('نگاشت‌های پروفایل','/tax/profiles/:id/product-mappings',[id('id','پروفایل',{required:true})],{pathFields:['id']}),
                post('ثبت نگاشت','/tax/product-mappings',[id('profile_id','پروفایل',{required:true}),id('product_id','کالا',{required:true}),text('tax_product_id','شناسه مالیاتی',{required:true}),text('measurement_unit','واحد',{required:true}),F('vat_rate_basis_points','VAT bp','number',{min:0,max:10000,required:true})]),
            ]),
            section('invoices','صورتحساب رسمی',[
                list('صورتحساب‌ها','/tax/invoices',[id('profile_id','پروفایل'),text('status','وضعیت'),id('sales_order_id','سفارش'),date('from','از'),date('to','تا')]),
                post('ایجاد صورتحساب','/tax/invoices',[id('profile_id','پروفایل',{required:true}),id('sales_order_id','سفارش',{required:true}),F('invoice_type','نوع','number',{min:1}),F('invoice_pattern','الگو','number',{min:1}),select('settlement_method','تسویه',[['cash','نقدی'],['credit','اعتباری'],['mixed','ترکیبی']],{required:true}),money('cash_irr','نقدی'),money('credit_irr','اعتباری'),common.idem,common.corr,group('buyer','خریدار',[select('buyer_type','نوع',[['legal','حقوقی'],['natural','حقیقی'],['consumer','مصرف‌کننده']],{required:true}),text('national_id','شناسه ملی'),text('economic_code','کد اقتصادی'),text('name','نام'),text('postal_code','کدپستی'),text('address','نشانی')])],{wide:true}),
                detail('جزئیات صورتحساب','/tax/invoices/:id',[id('id','صورتحساب',{required:true})],{pathFields:['id']}),
                post('فریز','/tax/invoices/:id/freeze',[id('id','صورتحساب',{required:true})],{pathFields:['id']}),
                post('ارسال','/tax/invoices/:id/submit',[id('id','صورتحساب',{required:true})],{pathFields:['id']}),
                post('استعلام','/tax/invoices/:id/inquire',[id('id','صورتحساب',{required:true})],{pathFields:['id']}),
                post('اصلاحی','/tax/invoices/:id/correction',[id('id','صورتحساب اصلی',{required:true}),text('reason','دلیل',{required:true}),common.idem],{pathFields:['id']}),
                post('ابطال','/tax/invoices/:id/cancellation',[id('id','صورتحساب اصلی',{required:true}),text('reason','دلیل',{required:true}),common.idem],{pathFields:['id']}),
                post('برگشت از فروش','/tax/invoices/:id/return',[id('id','صورتحساب اصلی',{required:true}),text('reason','دلیل',{required:true}),common.idem],{pathFields:['id']}),
            ]),
        ],
        settings: [
            section('environment','سلامت و نسخه',[
                {kind:'health',title:'وضعیت نصب',wide:true},
                detail('گزارش کامل محیط','/environment',[],{auto:true,wide:true}),
            ]),
            section('links','دسترسی سریع',[
                {kind:'links',title:'تنظیمات تخصصی',wide:true,links:[['سامانه مؤدیان','admin.php?page=rishe-tax'],['خزانه‌داری','admin.php?page=rishe-treasury'],['لجستیک','admin.php?page=rishe-logistics'],['مرکز عملیات','admin.php?page=rishe-operations'],['تحلیل و داشبورد','admin.php?page=rishe-analytics']]},
            ]),
        ],
    };

    const state = {sections: modules[boot.module] || [], active: null};
    const tabs = $('[data-rishe-role="tabs"]');
    const content = $('[data-rishe-role="content"]');
    const errorBox = $('[data-rishe-role="error"]');
    const successBox = $('[data-rishe-role="success"]');
    const dialog = $('[data-rishe-role="dialog"]');

    function message(type, value) {
        const box = type === 'error' ? errorBox : successBox;
        (type === 'error' ? successBox : errorBox).classList.add('hidden');
        $('p', box).textContent = value;
        box.classList.remove('hidden');
        if (type === 'success') setTimeout(() => box.classList.add('hidden'), 4500);
    }
    function defaultValue(v) { return typeof v === 'function' ? v() : (v ?? ''); }
    function renderField(f, prefix = '') {
        const name = prefix ? `${prefix}.${f.name}` : f.name;
        const required = f.required ? ' required' : '';
        const wide = f.wide ? ' is-wide' : '';
        const star = f.required ? '<b class="rishe-admin__required">*</b>' : '';
        if (f.type === 'group') return `<fieldset class="rishe-admin__group"><h4>${esc(f.label)} ${star}</h4><div class="rishe-admin__group-fields">${f.fields.map(x => renderField(x,name)).join('')}</div></fieldset>`;
        if (f.type === 'rows') return `<div class="rishe-admin__repeater${wide}" data-repeater="${esc(name)}" data-min="${f.min||0}" data-object="${f.object?'1':'0'}"><div class="rishe-admin__repeater-head"><strong>${esc(f.label)} ${star}</strong><button type="button" class="button button-small" data-add>+ ردیف</button></div><div data-rows></div><template>${`<div class="rishe-admin__repeater-row" data-row>${f.fields.map(x=>renderField({...x,wide:false},`${name}.__I__`)).join('')}<button type="button" class="button-link-delete" data-remove>حذف</button></div>`}</template></div>`;
        let control;
        if (f.type === 'select') control = `<select name="${esc(name)}"${required}>${f.options.map(([v,l])=>`<option value="${esc(v)}"${String(defaultValue(f.default))===String(v)?' selected':''}>${esc(l)}</option>`).join('')}</select>`;
        else if (f.type === 'textarea') control = `<textarea name="${esc(name)}"${required}>${esc(defaultValue(f.default))}</textarea>`;
        else if (f.type === 'checkbox') return `<label class="rishe-admin__field${wide}"><span><input name="${esc(name)}" type="checkbox" value="1"> ${esc(f.label)}</span></label>`;
        else control = `<input name="${esc(name)}" type="${esc(f.type)}" value="${esc(defaultValue(f.default))}"${required}${f.min!==undefined?` min="${f.min}"`:''}${f.max!==undefined?` max="${f.max}"`:''}${f.step!==undefined?` step="${f.step}"`:''}>`;
        return `<label class="rishe-admin__field${wide}"><span>${esc(f.label)} ${star}${f.suffix?` <small>(${esc(f.suffix)})</small>`:''}</span>${control}</label>`;
    }
    function initRows(parent) {
        $$('[data-repeater]',parent).forEach(box=>{
            const target=$('[data-rows]',box), tpl=$('template',box);
            const add=()=>{const i=target.querySelectorAll('[data-row]').length;target.insertAdjacentHTML('beforeend',tpl.innerHTML.replaceAll('__I__',i));};
            $('[data-add]',box).onclick=add;
            target.onclick=e=>{if(e.target.matches('[data-remove]'))e.target.closest('[data-row]').remove();};
            for(let i=0;i<Number(box.dataset.min||0);i++)add();
        });
    }
    function put(target,path,value){const p=path.split('.');let c=target;p.forEach((k,i)=>{if(i===p.length-1)c[k]=value;else{if(c[k]===undefined)c[k]=/^\d+$/.test(p[i+1])?[]:{};c=c[k];}});}
    function payload(form){const out={};$$('[name]',form).forEach(x=>{if(x.value===''&&!x.checked)return;let v=x.type==='checkbox'?x.checked:x.value;if(x.type==='number')v=Number(v);if(x.type==='datetime-local')v=v.replace('T',' ')+':00';put(out,x.name,v);});$$('[data-repeater][data-object="1"]',form).forEach(box=>{const n=box.dataset.repeater, parts=n.split('.'), k=parts.pop();let parent=out;parts.forEach(p=>parent=parent[p]);const arr=parent[k]||[],obj={};arr.forEach(r=>{if(r&&r.key)obj[r.key]=r.value??'';});parent[k]=obj;});return out;}
    function pathOf(card,data){let p=card.path;(card.pathFields||[]).forEach(k=>{p=p.replace(`:${k}`,encodeURIComponent(data[k]));delete data[k];});return p;}
    async function call(path,options={}){return api({path:`${root}${path}`,...options});}
    function show(title,value){$('[data-rishe-role="dialog-title"]',dialog).textContent=title;$('[data-rishe-role="dialog-body"]',dialog).innerHTML=`<pre class="rishe-admin__json-view">${esc(JSON.stringify(value,null,2))}</pre>`;dialog.showModal&&dialog.showModal();}
    function flatten(row){const o={};Object.entries(row||{}).forEach(([k,v])=>o[k]=(v&&typeof v==='object')?JSON.stringify(v):v);return o;}
    function table(holder,rowsData){if(!Array.isArray(rowsData)||!rowsData.length){holder.innerHTML='<table class="rishe-admin__table"><tbody><tr><td class="rishe-admin__empty">داده‌ای وجود ندارد.</td></tr></tbody></table>';return;}const flat=rowsData.map(flatten),keys=[...new Set(flat.flatMap(Object.keys))].slice(0,12);holder.innerHTML=`<table class="rishe-admin__table"><thead><tr>${keys.map(k=>`<th>${esc(k.replaceAll('_',' '))}</th>`).join('')}<th>جزئیات</th></tr></thead><tbody>${flat.map((r,i)=>`<tr>${keys.map(k=>`<td>${esc(r[k]??'—')}</td>`).join('')}<td><button class="button button-small" data-view="${i}">نمایش</button></td></tr>`).join('')}</tbody></table>`;$$('[data-view]',holder).forEach(b=>b.onclick=()=>show('جزئیات',rowsData[Number(b.dataset.view)]));}
    function body(card){if(card.kind==='form'||card.kind==='detail')return `<form class="rishe-admin__form" data-form>${card.fields.map(renderField).join('')}<div class="rishe-admin__form-actions"><button class="button button-primary" type="submit">${card.kind==='detail'?'نمایش':'ثبت'}</button><button class="button" type="reset">پاک‌کردن</button><span class="spinner"></span></div></form><div data-result></div>`;if(card.kind==='list')return `${card.filters.length?`<form class="rishe-admin__filters" data-filters>${card.filters.map(renderField).join('')}<button class="button button-primary">فیلتر</button></form>`:''}<div class="rishe-admin__table-wrap" data-table><table class="rishe-admin__table"><tbody><tr><td class="rishe-admin__empty">در حال دریافت…</td></tr></tbody></table></div>`;if(card.kind==='health')return `<div class="rishe-admin__summary"><div class="rishe-admin__metric"><span>نسخه افزونه</span><strong>${esc(boot.version)}</strong></div><div class="rishe-admin__metric"><span>نسخه Schema</span><strong>${esc(boot.databaseVersion||'—')}</strong></div><div class="rishe-admin__metric"><span>PHP</span><strong>8.1+</strong></div><div class="rishe-admin__metric"><span>Database</span><strong>MySQL 8 / MariaDB 10.6</strong></div></div>`;if(card.kind==='links')return card.links.map(([l,h])=>`<p><a class="button button-primary" href="${esc(h)}">${esc(l)}</a></p>`).join('');return '';}
    function init(card,node){initRows(node);if(card.kind==='form'||card.kind==='detail'){const form=$('[data-form]',node);form.onsubmit=async e=>{e.preventDefault();const spin=$('.spinner',form);spin.classList.add('is-active');const data=payload(form),path=pathOf(card,data);try{const res=await call(path,card.kind==='detail'?{method:'GET'}:{method:'POST',data});$('[data-result]',node).innerHTML='<p><button class="button button-small" data-result-button>مشاهده نتیجه</button></p>';$('[data-result-button]',node).onclick=()=>show('نتیجه عملیات',res);message('success','عملیات با موفقیت انجام شد.');}catch(err){message('error',err.message||'خطای ناشناخته');}finally{spin.classList.remove('is-active');}};if(card.auto)form.dispatchEvent(new Event('submit',{cancelable:true}));}if(card.kind==='list'){const holder=$('[data-table]',node),filters=$('[data-filters]',node);const load=async()=>{const data=filters?payload(filters):{},path=pathOf(card,data),q=new URLSearchParams();Object.entries(data).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)q.set(k,String(v));});try{const res=await call(path+(q.toString()?`?${q}`:''),{method:'GET'});let rowsData=card.response===null?res:res[card.response||'rows'];if(!Array.isArray(rowsData)&&rowsData&&typeof rowsData==='object')rowsData=Object.entries(rowsData).map(([k,v])=>typeof v==='object'?{key:k,...v}:{key:k,value:v});table(holder,rowsData);}catch(err){holder.innerHTML=`<div class="notice notice-error inline"><p>${esc(err.message||'خطا')}</p></div>`;}};if(filters)filters.onsubmit=e=>{e.preventDefault();load();};load();}}
    function render(sec){content.innerHTML=`<div class="rishe-admin__toolbar"><div><h2>${esc(sec.title)}</h2><p>${esc(sec.description||'')}</p></div><button class="button" data-refresh-section>تازه‌سازی</button></div><div class="rishe-admin__grid">${sec.cards.map((c,i)=>`<section class="rishe-admin__panel${c.wide?' rishe-admin__panel--wide':''}" data-card="${i}"><div class="rishe-admin__panel-head"><h3>${esc(c.title)}</h3></div><div>${body(c)}</div></section>`).join('')}</div>`;sec.cards.forEach((c,i)=>init(c,$(`[data-card="${i}"]`,content)));$('[data-refresh-section]',content).onclick=()=>render(sec);}
    function bootUi(){if(!state.sections.length){content.innerHTML='<div class="notice notice-error"><p>ماژول پیدا نشد.</p></div>';return;}tabs.innerHTML=state.sections.map((s,i)=>`<button data-section="${esc(s.id)}" class="${i?'':'is-active'}"><span>${esc(s.title)}</span><small>${i+1}</small></button>`).join('');state.active=state.sections[0].id;render(state.sections[0]);$$('[data-section]',tabs).forEach(b=>b.onclick=()=>{$$('[data-section]',tabs).forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');state.active=b.dataset.section;render(state.sections.find(x=>x.id===state.active));});}
    $('[data-rishe-command="refresh"]').onclick=()=>render(state.sections.find(x=>x.id===state.active));
    $('[data-rishe-command="help"]').onclick=()=>show('راهنما',{module:boot.module,version:boot.version,tip:'ابتدا اطلاعات پایه را بسازید؛ شناسه نتیجه را در عملیات بعدی استفاده کنید. همه درخواست‌ها با Nonce و Capability وردپرس محافظت می‌شوند.'});
    bootUi();
})();
