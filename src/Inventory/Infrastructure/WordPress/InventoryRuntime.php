<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure\WordPress;

use Throwable;

final class InventoryRuntime
{
    public const HOOK = 'rishe_inventory_expire_reservations';

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('init', [$this, 'schedule']);
        add_action(self::HOOK, [$this, 'expire']);
    }

    /** @param array<string, array<string, mixed>> $schedules @return array<string, array<string, mixed>> */
    public function schedules(array $schedules): array
    {
        $schedules['rishe_five_minutes'] = [
            'interval' => 300,
            'display' => 'Every five minutes (Rishe)',
        ];

        return $schedules;
    }

    public function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time() + 60, 'rishe_five_minutes', self::HOOK);
        }
    }

    public function expire(): void
    {
        try {
            (new InventoryCompletionFactory())->completion()->releaseExpiredReservations(
                250,
                max(1, (int) get_option('rishe_system_user_id', 1))
            );
        } catch (Throwable $exception) {
            do_action('rishe/inventory/expiration_error', $exception);
        }
    }
}
