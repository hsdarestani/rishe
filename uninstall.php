<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// ERP records are intentionally retained on uninstall.
// A future explicit data-erasure command must require a privileged confirmation flow.
delete_option('rishe_version');
delete_option('rishe_db_version');
delete_option('rishe_capabilities_version');

global $wpdb;
$like = $wpdb->esc_like('rishe_treasury_secret_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
