(() => {
    'use strict';

    const api = window.wp?.apiFetch;
    const cfg = window.risheReadOnlyAccess || {};
    const message = cfg.message || 'این حساب فقط برای مشاهده گزارش‌هاست.';

    if (api?.use) {
        api.use((options, next) => {
            const method = String(options?.method || 'GET').toUpperCase();
            if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                return Promise.reject({message});
            }

            return next(options);
        });
    }

    const mutationWords = /ثبت|ایجاد|ویرایش|حذف|تأیید|رد|انتقال|ورود کالا|پرداخت|تسویه|مدیریت ایونت|ارسال|دریافت/;
    const safeWords = /تازه.?سازی|تلاش دوباره|مشاهده|گزارش|جزئیات|فیلتر|جستجو/;

    function scrub() {
        const root = document.getElementById('rishe-business-app');
        if (!root) return;

        root.querySelectorAll('.rishe-section-actions button, .rishe-section-actions a').forEach(node => {
            const text = (node.textContent || '').trim();
            if (!safeWords.test(text)) {
                node.remove();
            }
        });

        root.querySelectorAll('button, a.rishe-button, a.rishe-link').forEach(node => {
            const text = (node.textContent || '').trim();
            if (mutationWords.test(text) && !safeWords.test(text)) {
                node.remove();
            }
        });

        root.querySelectorAll('a[href]').forEach(link => {
            const href = String(link.getAttribute('href') || '');
            if (
                href.includes('page=wc-orders')
                || href.includes('edit.php?post_type=product')
                || href.includes('post.php?post=')
                || href.includes('page=wc-admin')
                || href.includes('page=rishe-manufacturing')
                || href.includes('page=rishe-inventory')
                || href.includes('page=rishe-sales')
                || href.includes('page=rishe-procurement')
                || href.includes('page=rishe-accounting')
                || href.includes('page=rishe-treasury')
                || href.includes('page=rishe-logistics')
                || href.includes('page=rishe-b2b')
                || href.includes('page=rishe-operations')
                || href.includes('page=rishe-settings')
            ) {
                link.remove();
            }
        });
    }

    scrub();
    new MutationObserver(scrub).observe(document.documentElement, {childList: true, subtree: true});
})();
