<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class Capabilities
{
    private const VERSION = '2026071902';

    /** @var list<string> */
    private const ALL = [
        'manage_rishe',
        'rishe_view_reports',
        'rishe_manage_accounting',
        'rishe_manage_inventory',
        'rishe_manage_manufacturing',
        'rishe_manage_sales',
        'rishe_manage_crm',
        'rishe_manage_settings',
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
        if ($administrator === null) {
            return;
        }

        foreach (self::ALL as $capability) {
            $administrator->add_cap($capability);
        }

        update_option('rishe_capabilities_version', self::VERSION, true);
    }
}
