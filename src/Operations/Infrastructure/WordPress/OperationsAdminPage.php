<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\WordPress;

final class OperationsAdminPage
{
    /** @var list<string> */
    private const PAGE_HOOKS = [
        'toplevel_page_rishe',
        'rishe_page_rishe-operations',
        'rishe-erp_page_rishe-operations',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, self::PAGE_HOOKS, true)) {
            return;
        }
        wp_enqueue_style(
            'rishe-operations-admin',
            RISHE_URL . 'assets/admin/operations.css',
            [],
            RISHE_VERSION
        );
        wp_enqueue_script(
            'rishe-operations-admin',
            RISHE_URL . 'assets/admin/operations.js',
            ['wp-api-fetch'],
            RISHE_VERSION,
            true
        );
        wp_localize_script('rishe-operations-admin', 'risheOperations', [
            'root' => '/rishe/v1/operations',
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => RISHE_VERSION,
            'databaseVersion' => (string) get_option('rishe_db_version', ''),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('rishe_manage_operations')) {
            wp_die(esc_html__('You do not have permission to manage Rishe operations.', 'rishe'));
        }
        ?>
        <div class="wrap rishe-ops" id="rishe-operations-app">
            <div class="rishe-ops__hero">
                <div>
                    <p class="rishe-ops__eyebrow"><?php echo esc_html__('Operations control center', 'rishe'); ?></p>
                    <h1><?php echo esc_html__('Rishe ERP', 'rishe'); ?></h1>
                    <p><?php echo esc_html__('Monitor integrations, retry failed work, inspect diagnostics, and move safe configuration between environments.', 'rishe'); ?></p>
                </div>
                <div class="rishe-ops__hero-actions">
                    <span class="rishe-ops__version">v<?php echo esc_html(RISHE_VERSION); ?></span>
                    <button type="button" class="button button-primary" data-rishe-action="refresh">
                        <?php echo esc_html__('Refresh', 'rishe'); ?>
                    </button>
                </div>
            </div>

            <div class="notice notice-error inline hidden" data-rishe-role="error"><p></p></div>
            <div class="notice notice-success inline hidden" data-rishe-role="success"><p></p></div>

            <section class="rishe-ops__cards" data-rishe-role="metrics" aria-live="polite"></section>

            <div class="rishe-ops__layout">
                <section class="rishe-ops__panel rishe-ops__panel--wide">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('Background execution', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('Operation jobs', 'rishe'); ?></h2>
                        </div>
                        <span class="rishe-ops__badge" data-rishe-role="scheduler">—</span>
                    </div>
                    <form class="rishe-ops__job-form" data-rishe-role="job-form">
                        <label>
                            <span><?php echo esc_html__('Job type', 'rishe'); ?></span>
                            <select name="job_type" required data-rishe-role="job-types"></select>
                        </label>
                        <label>
                            <span><?php echo esc_html__('Invoice / shipment ID', 'rishe'); ?></span>
                            <input name="aggregate_id" type="number" min="1" required>
                        </label>
                        <label>
                            <span><?php echo esc_html__('Idempotency key', 'rishe'); ?></span>
                            <input name="idempotency_key" type="text" maxlength="191" required>
                        </label>
                        <button class="button button-primary" type="submit"><?php echo esc_html__('Queue job', 'rishe'); ?></button>
                    </form>
                    <div class="rishe-ops__table-wrap">
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php echo esc_html__('ID', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Type', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Reference', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Status', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Attempts', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Scheduled', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Actions', 'rishe'); ?></th>
                            </tr></thead>
                            <tbody data-rishe-role="jobs"><tr><td colspan="7"><?php echo esc_html__('Loading…', 'rishe'); ?></td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('System health', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('Diagnostics', 'rishe'); ?></h2>
                        </div>
                        <span class="rishe-ops__status" data-rishe-role="health-status">—</span>
                    </div>
                    <div class="rishe-ops__diagnostics" data-rishe-role="diagnostics"></div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('Action required', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('Open incidents', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <div data-rishe-role="incidents"></div>
                </section>

                <section class="rishe-ops__panel">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('Safe portability', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('Configuration package', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <p><?php echo esc_html__('Exports only allowlisted non-secret settings. Imports require preview and checksum confirmation.', 'rishe'); ?></p>
                    <div class="rishe-ops__config-actions">
                        <button type="button" class="button" data-rishe-action="export-config"><?php echo esc_html__('Export JSON', 'rishe'); ?></button>
                        <label class="button">
                            <?php echo esc_html__('Choose import file', 'rishe'); ?>
                            <input type="file" accept="application/json,.json" hidden data-rishe-role="import-file">
                        </label>
                    </div>
                    <div class="rishe-ops__config-preview" data-rishe-role="config-preview"></div>
                </section>

                <section class="rishe-ops__panel rishe-ops__panel--wide">
                    <div class="rishe-ops__panel-header">
                        <div>
                            <p class="rishe-ops__eyebrow"><?php echo esc_html__('Immutable trail', 'rishe'); ?></p>
                            <h2><?php echo esc_html__('Recent audit events', 'rishe'); ?></h2>
                        </div>
                    </div>
                    <div class="rishe-ops__table-wrap">
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php echo esc_html__('Time', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Event', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Aggregate', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Actor', 'rishe'); ?></th>
                                <th><?php echo esc_html__('Correlation', 'rishe'); ?></th>
                            </tr></thead>
                            <tbody data-rishe-role="audit"><tr><td colspan="5"><?php echo esc_html__('Loading…', 'rishe'); ?></td></tr></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }
}
