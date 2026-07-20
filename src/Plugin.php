<?php

declare(strict_types=1);

namespace Rishe;

use Rishe\Accounting\Infrastructure\WordPress\AccountingRestApi;
use Rishe\Analytics\Infrastructure\WordPress\AnalyticsRestApi;
use Rishe\Analytics\Infrastructure\WordPress\AnalyticsRuntime;
use Rishe\B2B\Infrastructure\WordPress\B2BRestApi;
use Rishe\Deployment\Infrastructure\WordPress\RisheCliRegistrar;
use Rishe\Infrastructure\Database\Migrator;
use Rishe\Infrastructure\WordPress\AdminMenu;
use Rishe\Infrastructure\WordPress\Capabilities;
use Rishe\Infrastructure\WordPress\PersianAdminLocalization;
use Rishe\Infrastructure\WordPress\RestApi;
use Rishe\Inventory\Infrastructure\WordPress\InventoryRestApi;
use Rishe\Logistics\Infrastructure\WordPress\LogisticsRestApi;
use Rishe\Manufacturing\Infrastructure\WordPress\ManufacturingRestApi;
use Rishe\Operations\Infrastructure\WordPress\OperationsRestApi;
use Rishe\Operations\Infrastructure\WordPress\OperationsRuntime;
use Rishe\Procurement\Infrastructure\WordPress\ProcurementRestApi;
use Rishe\Sales\Infrastructure\WordPress\SalesRestApi;
use Rishe\Tax\Infrastructure\WordPress\TaxRestApi;
use Rishe\Treasury\Infrastructure\WordPress\TreasuryRestApi;
use Rishe\WooCommerce\Infrastructure\WordPress\WooCommerceSyncAdminPage;
use Rishe\WooCommerce\Infrastructure\WordPress\WooCommerceSyncRestApi;
use Rishe\WooCommerce\Infrastructure\WordPress\WooCommerceSyncRuntime;

final class Plugin
{
    public function boot(): void
    {
        load_plugin_textdomain('rishe', false, dirname(plugin_basename(RISHE_FILE)) . '/languages');

        $migrator = new Migrator();
        $migrator->maybeMigrate();
        Capabilities::maybeGrant();

        (new AdminMenu())->register();
        (new PersianAdminLocalization())->register();
        (new RestApi())->register();
        (new AccountingRestApi())->register();
        (new InventoryRestApi())->register();
        (new ManufacturingRestApi())->register();
        (new SalesRestApi())->register();
        (new TreasuryRestApi())->register();
        (new ProcurementRestApi())->register();
        (new B2BRestApi())->register();
        (new LogisticsRestApi())->register();
        (new TaxRestApi())->register();
        (new OperationsRestApi())->register();
        (new AnalyticsRestApi())->register();
        (new OperationsRuntime())->register();
        (new AnalyticsRuntime())->register();
        (new WooCommerceSyncAdminPage())->register();
        (new WooCommerceSyncRestApi())->register();
        (new WooCommerceSyncRuntime())->register();
        (new RisheCliRegistrar())->register();

        do_action('rishe/booted', $this);
    }
}
