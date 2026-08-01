<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class Capabilities
{
    private const VERSION = '2026080101';

    /** @var list<string> */
    private const ALL = [
        'manage_rishe',
        'rishe_view_reports',
        'rishe_manage_accounting',
        'rishe_manage_inventory',
        'rishe_manage_manufacturing',
        'rishe_manage_sales',
        'rishe_manage_crm',
        'rishe_manage_treasury',
        'rishe_manage_procurement',
        'rishe_manage_b2b',
        'rishe_manage_logistics',
        'rishe_manage_tax',
        'rishe_manage_operations',
        'rishe_manage_analytics',
        'rishe_manage_settings',
    ];

    /** @var array<string, array{title:string,caps:list<string>}> */
    private const ROLE_PRESETS = [
        'rishe_finance_manager' => [
            'title' => 'ریشه — مسئول مالی و حسابداری',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_accounting', 'rishe_manage_treasury', 'rishe_manage_tax'],
        ],
        'rishe_procurement_manager' => [
            'title' => 'ریشه — مسئول بازرگانی و تأمین',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_procurement'],
        ],
        'rishe_inventory_manager' => [
            'title' => 'ریشه — مسئول انبار',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_inventory', 'rishe_manage_manufacturing'],
        ],
        'rishe_sales_marketing_manager' => [
            'title' => 'ریشه — مسئول فروش و بازاریابی',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_sales', 'rishe_manage_crm', 'rishe_manage_analytics'],
        ],
        'rishe_b2b_manager' => [
            'title' => 'ریشه — مسئول فروش B2B',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_b2b', 'rishe_manage_sales'],
        ],
        'rishe_logistics_manager' => [
            'title' => 'ریشه — مسئول لجستیک',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_logistics', 'rishe_manage_inventory'],
        ],
        'rishe_branch_supervisor' => [
            'title' => 'ریشه — سرپرست شعبه یا ایونت',
            'caps' => ['manage_rishe', 'rishe_view_reports', 'rishe_manage_sales', 'rishe_manage_inventory'],
        ],
        'rishe_cashier' => [
            'title' => 'ریشه — فروشنده یا صندوقدار',
            'caps' => ['manage_rishe', 'rishe_manage_sales'],
        ],
        'rishe_report_viewer' => [
            'title' => 'ریشه — مشاهده‌گر مدیریتی',
            'caps' => ['manage_rishe', 'rishe_view_reports'],
        ],
    ];

    public static function maybeGrant(): void
    {
        if ((string) get_option('rishe_capabilities_version', '') === self::VERSION) {
            return;
        }

        self::grant();
    }

    public static function grant(): void
    {
        $administrator = get_role('administrator');
        if ($administrator !== null) {
            foreach (self::ALL as $capability) {
                $administrator->add_cap($capability);
            }
        }

        foreach (self::ROLE_PRESETS as $slug => $definition) {
            $role = get_role($slug);
            if ($role === null) {
                add_role($slug, $definition['title'], ['read' => true]);
                $role = get_role($slug);
            }
            if ($role === null) {
                continue;
            }
            foreach (self::ALL as $capability) {
                $role->remove_cap($capability);
            }
            $role->add_cap('read');
            foreach ($definition['caps'] as $capability) {
                $role->add_cap($capability);
            }
        }

        update_option('rishe_capabilities_version', self::VERSION, true);
    }
}
