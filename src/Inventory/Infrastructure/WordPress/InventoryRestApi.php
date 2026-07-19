<?php

declare(strict_types=1);

namespace Rishe\Inventory\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\Exception\InventoryDomainException;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class InventoryRestApi
{
    private InventoryService $service;

    public function __construct(?InventoryService $service = null)
    {
        $this->service = $service ?? new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            new TransactionManager(),
            new AuditLogger()
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_inventory');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/inventory/warehouses', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createWarehouse'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/products', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createProduct'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/receipts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'receiveStock'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/reservations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reserveStock'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/reservations/(?P<id>\d+)/release', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'releaseReservation'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/reservations/(?P<id>\d+)/commit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'commitReservation'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/transfers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'transferStock'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/inventory/stock', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'stockSummary'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/inventory/ledger', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'ledger'],
            'permission_callback' => $report,
        ]);
    }

    public function createWarehouse(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['id' => $this->service->createWarehouse($this->payload($request))], 201);
    }

    public function createProduct(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['id' => $this->service->createProduct($this->payload($request))], 201);
    }

    public function receiveStock(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'batch_id' => $this->service->receiveStock($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function reserveStock(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'reservation_id' => $this->service->reserveStock($this->payload($request), get_current_user_id()),
            'status' => 'active',
        ], 201);
    }

    public function releaseReservation(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $this->service->releaseReservation((int) $request['id'], get_current_user_id());

            return ['reservation_id' => (int) $request['id'], 'status' => 'released'];
        });
    }

    public function commitReservation(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $result = $this->service->commitReservation((int) $request['id'], get_current_user_id());

            return ['reservation_id' => (int) $request['id'], 'status' => 'committed'] + $result;
        });
    }

    public function transferStock(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->transferStock($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function stockSummary(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->service->stockSummary($request->get_params()),
        ]);
    }

    public function ledger(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->service->ledger($request->get_params()),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new InventoryDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $successStatus);
        } catch (InventoryDomainException $exception) {
            return new WP_REST_Response(
                ['code' => 'rishe_inventory_validation', 'message' => $exception->getMessage()],
                422
            );
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(
                ['code' => 'rishe_inventory_conflict', 'message' => $exception->getMessage()],
                409
            );
        } catch (Throwable $exception) {
            do_action('rishe/inventory/error', $exception);

            return new WP_REST_Response(
                ['code' => 'rishe_inventory_error', 'message' => 'An unexpected inventory error occurred.'],
                500
            );
        }
    }
}
