<?php

declare(strict_types=1);

namespace Rishe\WooCommerce\Infrastructure\WordPress;

use Rishe\WooCommerce\Application\WooCommerceSyncService;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WooCommerceSyncRestApi
{
    public function __construct(private ?WooCommerceSyncService $service = null)
    {
        $this->service ??= new WooCommerceSyncService();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $permission = static fn (): bool => current_user_can('rishe_manage_settings') || current_user_can('manage_rishe');
        register_rest_route('rishe/v1', '/integrations/woocommerce/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'status'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'settings'],
                'permission_callback' => $permission,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'saveSettings'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/products/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'importProducts'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/orders/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'importOrders'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/stock/push', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pushStock'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/stock/pull', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pullStock'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/reconcile', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reconcile'],
            'permission_callback' => $permission,
        ]);
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->service->status());
    }

    public function settings(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->service->settings());
    }

    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->saveSettings($this->payload($request)));
    }

    public function importProducts(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->service->importProducts());
    }

    public function importOrders(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->payload($request, true);

        return $this->execute(fn (): array => $this->service->importRecentOrders((int) ($payload['limit'] ?? 50)));
    }

    public function pushStock(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->payload($request, true);
        $id = isset($payload['product_id']) && (int) $payload['product_id'] > 0 ? (int) $payload['product_id'] : null;

        return $this->execute(fn (): array => $this->service->pushAll($id));
    }

    public function pullStock(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->payload($request, true);
        $id = isset($payload['wc_product_id']) && (int) $payload['wc_product_id'] > 0 ? (int) $payload['wc_product_id'] : null;

        return $this->execute(fn (): array => $this->service->pullAll($id));
    }

    public function reconcile(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->service->reconcile());
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request, bool $allowEmpty = false): array
    {
        $payload = $request->get_json_params();
        if ($allowEmpty && ($payload === null || $payload === [])) {
            return [];
        }
        if (!is_array($payload)) {
            throw new RuntimeException('بدنه درخواست باید JSON معتبر باشد.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), 200);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_woocommerce_sync_error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            do_action('rishe/woocommerce/error', $exception, 'rest');

            return new WP_REST_Response([
                'code' => 'rishe_woocommerce_unexpected_error',
                'message' => 'اجرای اتصال ووکامرس با خطای غیرمنتظره روبه‌رو شد.',
            ], 500);
        }
    }
}
