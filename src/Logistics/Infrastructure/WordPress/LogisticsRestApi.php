<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Logistics\Application\LogisticsService;
use Rishe\Logistics\Domain\Exception\LogisticsDomainException;
use Rishe\Logistics\Infrastructure\HmacCarrierWebhookVerifier;
use Rishe\Logistics\Infrastructure\WpCarrierGatewayRegistry;
use Rishe\Logistics\Infrastructure\WpCarrierSecretVault;
use Rishe\Logistics\Infrastructure\WpLogisticsAccountingGateway;
use Rishe\Logistics\Infrastructure\WpLogisticsTreasuryGateway;
use Rishe\Logistics\Infrastructure\WpdbLogisticsRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Infrastructure\WpdbTreasuryRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class LogisticsRestApi
{
    private LogisticsService $service;

    public function __construct(?LogisticsService $service = null)
    {
        if ($service !== null) {
            $this->service = $service;

            return;
        }
        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $vault = new WpCarrierSecretVault();
        $accounting = new AccountingService(
            new WpdbAccountingRepository(),
            $transactions,
            $audit
        );
        $this->service = new LogisticsService(
            new WpdbLogisticsRepository(),
            new WpCarrierGatewayRegistry($vault),
            $vault,
            new HmacCarrierWebhookVerifier($vault),
            new WpLogisticsTreasuryGateway(new WpdbTreasuryRepository(), $audit),
            new WpLogisticsAccountingGateway($accounting),
            $transactions,
            $audit
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_logistics');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/logistics/carriers', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'upsertCarrier'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listCarriers'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/logistics/carriers/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getCarrier'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createShipment'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listShipments'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getShipment'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/quote', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'quoteShipment'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/book', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bookShipment'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancelShipment'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/tracking/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'refreshTracking'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/costs', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'recordCarrierCost'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/logistics/shipments/(?P<id>\d+)/settlements', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'settleCarrierCost'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/integrations/logistics/(?P<carrier>[a-z0-9._-]+)/webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'carrierWebhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function upsertCarrier(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->upsertCarrier($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listCarriers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->carriers([
            'is_active' => $request->get_param('is_active'),
            'code' => $request->get_param('code'),
        ])]);
    }

    public function getCarrier(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->carrier((int) $request['id']));
    }

    public function createShipment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->createShipment($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listShipments(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->shipments([
            'sales_order_id' => $request->get_param('sales_order_id'),
            'carrier_id' => $request->get_param('carrier_id'),
            'status' => $request->get_param('status'),
            'tracking_number' => $request->get_param('tracking_number'),
        ])]);
    }

    public function getShipment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->shipment((int) $request['id']));
    }

    public function quoteShipment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);

            return $this->service->quoteShipment(
                (int) $request['id'],
                (int) ($payload['carrier_id'] ?? 0),
                isset($payload['service_code']) ? (string) $payload['service_code'] : null,
                get_current_user_id()
            );
        });
    }

    public function bookShipment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request, true);

            return $this->service->bookShipment(
                (int) $request['id'],
                isset($payload['carrier_id']) ? (int) $payload['carrier_id'] : null,
                isset($payload['service_code']) ? (string) $payload['service_code'] : null,
                get_current_user_id()
            );
        });
    }

    public function cancelShipment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->cancelShipment(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function refreshTracking(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->refreshTracking(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function recordCarrierCost(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->recordCarrierCost(
                (int) $request['id'],
                $this->payload($request),
                get_current_user_id()
            ),
            201
        );
    }

    public function settleCarrierCost(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);

            return $this->service->settleCarrierCost(
                (int) $request['id'],
                (int) ($payload['treasury_transaction_id'] ?? 0),
                (int) ($payload['amount_irr'] ?? 0),
                get_current_user_id()
            );
        }, 201);
    }

    public function carrierWebhook(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->processWebhook(
            (string) $request['carrier'],
            $request->get_body(),
            (string) ($request->get_header('x-carrier-signature')
                ?: $request->get_header('x-rishe-signature'))
        ));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request, bool $allowEmpty = false): array
    {
        $payload = $request->get_json_params();
        if ($allowEmpty && ($payload === null || $payload === [])) {
            return [];
        }
        if (!is_array($payload)) {
            throw new LogisticsDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (LogisticsDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 404);
        } catch (Throwable) {
            return new WP_REST_Response(['error' => 'Unexpected logistics error.'], 500);
        }
    }
}
