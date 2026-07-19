<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\B2B\Application\B2BService;
use Rishe\B2B\Domain\CommissionCalculator;
use Rishe\B2B\Domain\ConsignmentLineBalance;
use Rishe\B2B\Domain\CreditExposure;
use Rishe\B2B\Domain\Exception\B2BDomainException;
use Rishe\B2B\Infrastructure\WpB2BAccountingGateway;
use Rishe\B2B\Infrastructure\WpB2BInventoryGateway;
use Rishe\B2B\Infrastructure\WpB2BTreasuryGateway;
use Rishe\B2B\Infrastructure\WpdbB2BRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Infrastructure\WpdbTreasuryRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class B2BRestApi
{
    private B2BService $service;

    public function __construct(?B2BService $service = null)
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
        $this->service = new B2BService(
            new WpdbB2BRepository(),
            new WpB2BInventoryGateway($inventory),
            new WpB2BAccountingGateway($accounting),
            new WpB2BTreasuryGateway(new WpdbTreasuryRepository(), $audit),
            $transactions,
            $audit,
            new CommissionCalculator(),
            new CreditExposure(),
            new ConsignmentLineBalance()
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_b2b');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/b2b/accounts', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'upsertAccount'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listAccounts'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/b2b/accounts/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getAccount'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/b2b/accounts/(?P<id>\d+)/statement', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'statement'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/b2b/accounts/(?P<id>\d+)/settlements', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'settleAccount'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/consignment/dispatches', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createDispatch'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listDispatches'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/consignment/dispatches/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getDispatch'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/consignment/dispatches/(?P<id>\d+)/returns', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'returnConsignment'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/consignment/sales-reports', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'postSalesReport'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listSalesReports'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/consignment/sales-reports/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getSalesReport'],
            'permission_callback' => $report,
        ]);
    }

    public function upsertAccount(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->upsertAccount($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listAccounts(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->accounts([
            'account_type' => $request->get_param('account_type'),
            'status' => $request->get_param('status'),
        ])]);
    }

    public function getAccount(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->account((int) $request['id']));
    }

    public function statement(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->statement((int) $request['id'])]);
    }

    public function settleAccount(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);

            return $this->service->settleAccount(
                (int) $request['id'],
                (int) ($payload['treasury_transaction_id'] ?? 0),
                (int) ($payload['amount_irr'] ?? 0),
                get_current_user_id()
            );
        }, 201);
    }

    public function createDispatch(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->createDispatch($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listDispatches(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->dispatches([
            'account_id' => $request->get_param('account_id'),
            'status' => $request->get_param('status'),
        ])]);
    }

    public function getDispatch(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->dispatch((int) $request['id']));
    }

    public function returnConsignment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->returnConsignment(
                (int) $request['id'],
                $this->payload($request),
                get_current_user_id()
            ),
            201
        );
    }

    public function postSalesReport(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->postSalesReport($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listSalesReports(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->salesReports([
            'account_id' => $request->get_param('account_id'),
            'status' => $request->get_param('status'),
        ])]);
    }

    public function getSalesReport(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->salesReport((int) $request['id']));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new B2BDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (B2BDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 404);
        } catch (Throwable $exception) {
            return new WP_REST_Response(['error' => 'Unexpected B2B error.'], 500);
        }
    }
}
