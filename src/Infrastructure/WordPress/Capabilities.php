<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\WordPress;

final class Capabilities
{
    /** @var list<string> */
    private const ALL = [
        'manage_rishe',
        'rishe_view_reports',
        'rishe_manage_accounting',
        'rishe_manage_inventory',
        'rishe_manage_sales',
        'rishe_manage_crm',
        'rishe_manage_settings',
    ];

    public static function grant(): void
    {
        $administrator = get_role('administrator');
        if ($administrator === null) {
            return;
        }

        foreach (self::ALL as $capability) {
            $administrator->add_cap($capability);
        }
    }
}
