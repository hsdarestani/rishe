<?php

declare(strict_types=1);

namespace Rishe;

use Rishe\Accounting\Infrastructure\WordPress\AccountingRestApi;
use Rishe\Infrastructure\Database\Migrator;
use Rishe\Infrastructure\WordPress\AdminMenu;
use Rishe\Infrastructure\WordPress\RestApi;
use Rishe\Inventory\Infrastructure\WordPress\InventoryRestApi;

final class Plugin
{
    public function boot(): void
    {
        load_plugin_textdomain('rishe', false, dirname(plugin_basename(RISHE_FILE)) . '/languages');

        $migrator = new Migrator();
        $migrator->maybeMigrate();

        (new AdminMenu())->register();
        (new RestApi())->register();
        (new AccountingRestApi())->register();
        (new InventoryRestApi())->register();

        do_action('rishe/booted', $this);
    }
}
