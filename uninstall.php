<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// ERP records are intentionally retained on uninstall.
// A future explicit data-erasure command must require a privileged confirmation flow.
delete_option('rishe_version');
delete_option('rishe_db_version');
