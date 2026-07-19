<?php

declare(strict_types=1);

namespace Rishe;

use Rishe\Infrastructure\Database\Migrator;
use Rishe\Infrastructure\WordPress\AdminMenu;
use Rishe\Infrastructure\WordPress\RestApi;

final class Plugin
{
    public function boot(): void
    {
        load_plugin_textdomain('rishe', false, dirname(plugin_basename(RISHE_FILE)) . '/languages');

        $migrator = new Migrator();
        $migrator->maybeMigrate();

        (new AdminMenu())->register();
        (new RestApi())->register();

        do_action('rishe/booted', $this);
    }
}
