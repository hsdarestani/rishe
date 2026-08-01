<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class BusinessDialogCompatibility
{
    /** @var list<string> */
    private const PAGES = [
        'rishe',
        'rishe-work-inventory',
        'rishe-work-sales',
        'rishe-work-procurement',
        'rishe-work-finance',
        'rishe-work-logistics',
        'rishe-work-b2b',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'attach'], 20);
    }

    public function attach(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if (!in_array($page, self::PAGES, true)) {
            return;
        }

        wp_add_inline_script(
            'rishe-business-admin',
            <<<'JS'
(() => {
    'use strict';

    const repairDialogFrame = () => {
        const frame = document.querySelector('form.rishe-business__dialog-frame');
        if (!frame) return;

        const replacement = document.createElement('div');
        replacement.className = frame.className;
        while (frame.firstChild) replacement.appendChild(frame.firstChild);
        frame.replaceWith(replacement);

        const dialog = replacement.closest('dialog');
        const closeButton = replacement.querySelector('.rishe-business__dialog-close');
        if (dialog && closeButton) {
            closeButton.type = 'button';
            closeButton.removeAttribute('value');
            closeButton.addEventListener('click', () => dialog.close());
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', repairDialogFrame, {once: true});
    } else {
        repairDialogFrame();
    }
})();
JS,
            'before'
        );
    }
}
