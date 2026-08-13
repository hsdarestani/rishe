<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

use WP_Admin_Bar;
use WP_User;

final class RestrictedAdminShell
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'pruneAdminMenu'], 9999);
        add_action('admin_init', [$this, 'guardAdminRoutes'], 1);
        add_action('admin_bar_menu', [$this, 'pruneAdminBar'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueReadOnlyAssets'], 50);
        add_filter('admin_body_class', [$this, 'adminBodyClass']);
        add_filter('login_redirect', [$this, 'loginRedirect'], 20, 3);
    }

    public function pruneAdminMenu(): void
    {
        if (!$this->isRestricted()) {
            return;
        }

        global $menu;
        if (!is_array($menu)) {
            return;
        }

        foreach ($menu as $index => $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug !== 'rishe') {
                unset($menu[$index]);
            }
        }
    }

    public function guardAdminRoutes(): void
    {
        if (!$this->isRestricted() || wp_doing_ajax()) {
            return;
        }

        global $pagenow;
        $current = is_string($pagenow) ? $pagenow : '';
        if (in_array($current, ['admin-ajax.php', 'admin-post.php'], true)) {
            return;
        }

        if ($current !== 'admin.php') {
            $this->redirectToLanding();
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page === '' || !in_array($page, $this->allowedPages(), true)) {
            $this->redirectToLanding();
        }
    }

    public function pruneAdminBar(WP_Admin_Bar $bar): void
    {
        if (!$this->isRestricted()) {
            return;
        }

        foreach (['wp-logo', 'site-name', 'updates', 'comments', 'new-content', 'customize', 'search', 'my-sites'] as $node) {
            $bar->remove_node($node);
        }
    }

    public function enqueueReadOnlyAssets(): void
    {
        if (!$this->isRestricted()) {
            return;
        }

        wp_enqueue_style(
            'rishe-read-only-access',
            RISHE_URL . 'assets/admin/read-only-access.css',
            [],
            RISHE_VERSION
        );

        if (!$this->isReportOnly()) {
            return;
        }

        wp_enqueue_script(
            'rishe-read-only-access',
            RISHE_URL . 'assets/admin/read-only-access.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-read-only-access', 'risheReadOnlyAccess', [
            'message' => 'این حساب فقط برای مشاهده گزارش‌هاست و اجازه ثبت یا ویرایش ندارد.',
        ]);
    }

    public function adminBodyClass(string $classes): string
    {
        if (!$this->isRestricted()) {
            return $classes;
        }

        $classes .= ' rishe-restricted-admin';
        if ($this->isReportOnly()) {
            $classes .= ' rishe-report-only';
        } elseif (current_user_can('rishe_manage_accounting')) {
            $classes .= ' rishe-finance-only';
        }

        return trim($classes);
    }

    public function loginRedirect(string $redirectTo, string $requestedRedirectTo, mixed $user): string
    {
        unset($requestedRedirectTo);

        if (!$user instanceof WP_User || !$user->exists() || !$user->has_cap('rishe_restricted_admin')) {
            return $redirectTo;
        }

        if ($user->has_cap('rishe_view_all_sections')) {
            return admin_url('admin.php?page=rishe');
        }

        if ($user->has_cap('rishe_manage_accounting')) {
            return admin_url('admin.php?page=rishe-work-finance');
        }

        return admin_url('admin.php?page=rishe');
    }

    /** @return list<string> */
    private function allowedPages(): array
    {
        if ($this->isReportOnly()) {
            return [
                'rishe',
                'rishe-work-inventory',
                'rishe-work-sales',
                'rishe-work-procurement',
                'rishe-work-finance',
                'rishe-work-logistics',
                'rishe-work-b2b',
                'rishe-analytics',
            ];
        }

        if (current_user_can('rishe_manage_accounting')) {
            return [
                'rishe-work-finance',
                'rishe-accounting-review',
                'rishe-accounting',
                'rishe-treasury',
                'rishe-tax',
            ];
        }

        return ['rishe'];
    }

    private function redirectToLanding(): never
    {
        $url = $this->isReportOnly()
            ? admin_url('admin.php?page=rishe')
            : admin_url('admin.php?page=rishe-work-finance');
        wp_safe_redirect($url);
        exit;
    }

    private function isRestricted(): bool
    {
        return is_user_logged_in()
            && current_user_can('rishe_restricted_admin')
            && !current_user_can('manage_options');
    }

    private function isReportOnly(): bool
    {
        return $this->isRestricted()
            && current_user_can('rishe_view_all_sections')
            && !current_user_can('manage_rishe');
    }
}
