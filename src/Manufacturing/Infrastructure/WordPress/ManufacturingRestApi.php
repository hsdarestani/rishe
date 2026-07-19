<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Manufacturing\Application\ManufacturingService;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;
use Rishe\Manufacturing\Domain\ProductionCostCalculator;
use Rishe\Manufacturing\Infrastructure\WpdbManufacturingRepository;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ManufacturingRestApi
{
    private ManufacturingService $service;

    public function __construct(?ManufacturingService $service = null)
    {
        $this->service = $service ?? new ManufacturingService(
            new WpdbManufacturingRepository(new FifoAllocator(), new ProductionCostCalculator()),
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
        $manage = static fn (): bool => current_user_can('rishe_manage_manufacturing');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/manufacturing/boms', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createBom'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listBoms'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/manufacturing/boms/(?P<id>\d+)/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activateBom'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/manufacturing/orders/execute', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'executeProduction'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/manufacturing/orders', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listOrders'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/manufacturing/orders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getOrder'],
            'permission_callback' => $report,
        ]);
    }

    public function createBom(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createBom($this->payload($request), get_current_user_id()),
            'status' => 'draft',
        ], 201);
    }

    public function activateBom(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $this->service->activateBom((int) $request['id'], get_current_user_id());

            return ['id' => (int) $request['id'], 'status' => 'active'];
        });
    }

    public function executeProduction(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->executeProduction($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listBoms(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->service->boms([
                'status' => $request->get_param('status'),
                'output_product_id' => $request->get_param('output_product_id'),
            ]),
        ]);
    }

    public function listOrders(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->service->productionOrders([
                'bom_id' => $request->get_param('bom_id'),
                'from' => $request->get_param('from'),
                'to' => $request->get_param('to'),
            ]),
        ]);
    }

    public function getOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->productionOrder((int) $request['id']));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new ManufacturingDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $successStatus);
        } catch (ManufacturingDomainException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_manufacturing_validation',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_manufacturing_conflict',
                'message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $exception) {
            do_action('rishe/manufacturing/error', $exception);

            return new WP_REST_Response([
                'code' => 'rishe_manufacturing_error',
                'message' => 'An unexpected manufacturing error occurred.',
            ], 500);
        }
    }
}
