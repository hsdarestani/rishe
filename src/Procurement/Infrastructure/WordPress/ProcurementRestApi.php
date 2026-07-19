<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Procurement\Application\ProcurementService;
use Rishe\Procurement\Domain\Exception\ProcurementDomainException;
use Rishe\Procurement\Domain\LandedCostAllocator;
use Rishe\Procurement\Domain\ReceiptProrator;
use Rishe\Procurement\Infrastructure\WpInventoryReceiptGateway;
use Rishe\Procurement\Infrastructure\WpProcurementAccountingGateway;
use Rishe\Procurement\Infrastructure\WpProcurementTreasuryGateway;
use Rishe\Procurement\Infrastructure\WpdbProcurementRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Infrastructure\WpdbTreasuryRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ProcurementRestApi
{
    private ProcurementService $service;

    public function __construct(?ProcurementService $service = null)
    {
        if ($service !== null) {
            $this->service = $service;

            return;
        }

        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $inventory = new InventoryService(
            new WpdbInventoryRepository(new FifoAllocator()),
            $transactions,
            $audit
        );
        $accounting = new AccountingService(
            new WpdbAccountingRepository(),
            $transactions,
            $audit
        );
        $this->service = new ProcurementService(
            new WpdbProcurementRepository(),
            new WpInventoryReceiptGateway($inventory),
            new WpProcurementAccountingGateway($accounting),
            new WpProcurementTreasuryGateway(new WpdbTreasuryRepository(), $audit),
            $transactions,
            $audit,
            new LandedCostAllocator(),
            new ReceiptProrator()
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_procurement');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/procurement/suppliers', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'upsertSupplier'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listSuppliers'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/procurement/suppliers/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getSupplier'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/procurement/suppliers/(?P<id>\d+)/statement', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'supplierStatement'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createPurchaseOrder'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listPurchaseOrders'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getPurchaseOrder'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders/(?P<id>\d+)/approve', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'approvePurchaseOrder'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancelPurchaseOrder'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders/(?P<id>\d+)/receipts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'receivePurchaseOrder'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/procurement/purchase-orders/(?P<id>\d+)/payments', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'registerSupplierPayment'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/procurement/receipts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listReceipts'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/procurement/receipts/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getReceipt'],
            'permission_callback' => $report,
        ]);
    }

    public function upsertSupplier(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->upsertSupplier($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listSuppliers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->suppliers([
            'is_active' => $request->get_param('is_active'),
        ])]);
    }

    public function getSupplier(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->supplier((int) $request['id']));
    }

    public function supplierStatement(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->service->supplierStatement((int) $request['id']),
        ]);
    }

    public function createPurchaseOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->createPurchaseOrder($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listPurchaseOrders(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->purchaseOrders([
            'supplier_id' => $request->get_param('supplier_id'),
            'warehouse_id' => $request->get_param('warehouse_id'),
            'status' => $request->get_param('status'),
        ])]);
    }

    public function getPurchaseOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->purchaseOrder((int) $request['id']));
    }

    public function approvePurchaseOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->approvePurchaseOrder(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function cancelPurchaseOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request, true);
            $this->service->cancelPurchaseOrder(
                (int) $request['id'],
                get_current_user_id(),
                (string) ($payload['reason'] ?? '')
            );

            return ['id' => (int) $request['id'], 'status' => 'cancelled'];
        });
    }

    public function receivePurchaseOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->receivePurchaseOrder(
                (int) $request['id'],
                $this->payload($request),
                get_current_user_id()
            ),
            201
        );
    }

    public function registerSupplierPayment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);

            return $this->service->registerSupplierPayment(
                (int) $request['id'],
                (int) ($payload['treasury_transaction_id'] ?? 0),
                (int) ($payload['amount_irr'] ?? 0),
                get_current_user_id()
            );
        }, 201);
    }

    public function listReceipts(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->receipts([
            'purchase_order_id' => $request->get_param('purchase_order_id'),
            'supplier_id' => $request->get_param('supplier_id'),
            'warehouse_id' => $request->get_param('warehouse_id'),
        ])]);
    }

    public function getReceipt(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->receipt((int) $request['id']));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request, bool $allowEmpty = false): array
    {
        $payload = $request->get_json_params();
        if ($allowEmpty && ($payload === null || $payload === [])) {
            return [];
        }
        if (!is_array($payload)) {
            throw new ProcurementDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (ProcurementDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 404);
        } catch (Throwable $exception) {
            return new WP_REST_Response(['error' => 'Unexpected procurement error.'], 500);
        }
    }
}
