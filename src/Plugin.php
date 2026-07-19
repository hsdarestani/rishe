<?php

declare(strict_types=1);

namespace Rishe;

use Rishe\Accounting\Infrastructure\WordPress\AccountingRestApi;
use Rishe\Infrastructure\Database\Migrator;
use Rishe\Infrastructure\WordPress\AdminMenu;
use Rishe\Infrastructure\WordPress\Capabilities;
use Rishe\Infrastructure\WordPress\RestApi;
use Rishe\Inventory\Infrastructure\WordPress\InventoryRestApi;
use Rishe\Manufacturing\Infrastructure\WordPress\ManufacturingRestApi;
use Rishe\Procurement\Infrastructure\WordPress\ProcurementRestApi;
use Rishe\Sales\Infrastructure\WordPress\SalesRestApi;
use Rishe\Treasury\Infrastructure\WordPress\TreasuryRestApi;

final class Plugin
{
    public function boot(): void
    {
        load_plugin_textdomain('rishe', false, dirname(plugin_basename(RISHE_FILE)) . '/languages');

        $migrator = new Migrator();
        $migrator->maybeMigrate();
        Capabilities::maybeGrant();

        (new AdminMenu())->register();
        (new RestApi())->register();
        (new AccountingRestApi())->register();
        (new InventoryRestApi())->register();
        (new ManufacturingRestApi())->register();
        (new SalesRestApi())->register();
        (new TreasuryRestApi())->register();
        (new ProcurementRestApi())->register();

        do_action('rishe/booted', $this);
    }
}
