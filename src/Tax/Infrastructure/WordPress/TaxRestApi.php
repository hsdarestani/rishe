<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Tax\Application\TaxService;
use Rishe\Tax\Domain\Exception\TaxDomainException;
use Rishe\Tax\Domain\TaxInvoiceNumberGenerator;
use Rishe\Tax\Domain\TaxTotals;
use Rishe\Tax\Infrastructure\RsaTaxPayloadSigner;
use Rishe\Tax\Infrastructure\WpTaxGatewayRegistry;
use Rishe\Tax\Infrastructure\WpTaxSecretVault;
use Rishe\Tax\Infrastructure\WpdbTaxRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TaxRestApi
{
    private TaxService $service;

    public function __construct(?TaxService $service = null)
    {
        if ($service !== null) {
            $this->service = $service;

            return;
        }
        $vault = new WpTaxSecretVault();
        $this->service = new TaxService(
            new WpdbTaxRepository(),
            new WpTaxGatewayRegistry($vault),
            $vault,
            new RsaTaxPayloadSigner(),
            new TransactionManager(),
            new AuditLogger(),
            new TaxInvoiceNumberGenerator(),
            new TaxTotals()
        );
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_tax');
        $report = static fn (): bool => current_user_can('rishe_view_reports');
        register_rest_route('rishe/v1', '/tax/profiles', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveProfile'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'profiles'], 'permission_callback' => $report],
        ]);
        register_rest_route('rishe/v1', '/tax/profiles/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'profile'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/tax/product-mappings', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'saveProductMapping'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/tax/profiles/(?P<id>\d+)/product-mappings', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'productMappings'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/tax/invoices', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createInvoice'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'invoices'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/tax/invoices/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'invoice'],
            'permission_callback' => $report,
        ]);
        foreach (['freeze', 'submit', 'inquire'] as $action) {
            register_rest_route('rishe/v1', '/tax/invoices/(?P<id>\d+)/' . $action, [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, $action],
                'permission_callback' => $manage,
            ]);
        }
        foreach (['correction', 'cancellation', 'return'] as $subject) {
            register_rest_route('rishe/v1', '/tax/invoices/(?P<id>\d+)/' . $subject, [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'derive'],
                'permission_callback' => $manage,
                'args' => ['subject' => ['default' => $subject]],
            ]);
        }
    }

    public function saveProfile(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->upsertProfile($this->payload($request), get_current_user_id()), 201);
    }

    public function profiles(): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->profiles()]);
    }

    public function profile(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->profile((int) $request['id']));
    }

    public function saveProductMapping(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->upsertProductMapping(
            $this->payload($request),
            get_current_user_id()
        ), 201);
    }

    public function productMappings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->productMappings((int) $request['id'])]);
    }

    public function createInvoice(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->createFromSalesOrder(
            $this->payload($request),
            get_current_user_id()
        ), 201);
    }

    public function invoices(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->invoices([
            'profile_id' => $request->get_param('profile_id'),
            'sales_order_id' => $request->get_param('sales_order_id'),
            'status' => $request->get_param('status'),
            'subject' => $request->get_param('subject'),
        ])]);
    }

    public function invoice(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->invoice((int) $request['id']));
    }

    public function freeze(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->freeze((int) $request['id'], get_current_user_id()));
    }

    public function submit(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->submit((int) $request['id'], get_current_user_id()));
    }

    public function inquire(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->inquire((int) $request['id'], get_current_user_id()));
    }

    public function derive(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->derive(
            (int) $request['id'],
            (string) $request->get_param('subject'),
            get_current_user_id()
        ), 201);
    }

    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new TaxDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (TaxDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 404);
        } catch (Throwable) {
            return new WP_REST_Response(['error' => 'Unexpected tax integration error.'], 500);
        }
    }
}
