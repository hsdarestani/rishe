<?php

declare(strict_types=1);

namespace Rishe\Treasury\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Treasury\Application\TreasuryService;
use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use Rishe\Treasury\Infrastructure\EncryptedOptionSecretStore;
use Rishe\Treasury\Infrastructure\WpPaymentLinkGateway;
use Rishe\Treasury\Infrastructure\WpSalesPaymentBridge;
use Rishe\Treasury\Infrastructure\WpdbTreasuryRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TreasuryRestApi
{
    private TreasuryService $service;

    public function __construct(?TreasuryService $service = null)
    {
        if ($service !== null) {
            $this->service = $service;

            return;
        }
        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $this->service = new TreasuryService(
            new WpdbTreasuryRepository(),
            new WpPaymentLinkGateway(new EncryptedOptionSecretStore()),
            new WpSalesPaymentBridge($transactions, $audit),
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
        $manage = static fn (): bool => current_user_can('rishe_manage_treasury');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/treasury/accounts', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createAccount'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'accounts'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/treasury/providers', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createProvider'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'providers'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/treasury/payment-links', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createPaymentLink'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'paymentLinks'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/treasury/transactions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'transactions'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/treasury/transactions/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'importTransaction'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/treasury/transactions/(?P<id>\d+)/matches', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'matchTransaction'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/treasury/settlements', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createSettlement'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'settlements'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/integrations/treasury/(?P<provider>[a-z0-9._-]+)/callback', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function createAccount(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createAccount($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function accounts(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->accounts([
            'type' => $request->get_param('type'),
            'is_active' => $request->get_param('is_active'),
        ])]);
    }

    public function createProvider(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createProvider($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function providers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->providers([
            'adapter' => $request->get_param('adapter'),
            'is_active' => $request->get_param('is_active'),
        ])]);
    }

    public function createPaymentLink(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);
            $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
            if (!isset($payload['callback_url']) && $provider !== '') {
                $payload['callback_url'] = rest_url('rishe/v1/integrations/treasury/' . $provider . '/callback');
            }

            return $this->service->createPaymentLink($payload, get_current_user_id());
        }, 201);
    }

    public function paymentLinks(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->paymentLinks([
            'provider_id' => $request->get_param('provider_id'),
            'sales_order_id' => $request->get_param('sales_order_id'),
            'status' => $request->get_param('status'),
        ])]);
    }

    public function importTransaction(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->importTransaction($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function transactions(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->transactions([
            'treasury_account_id' => $request->get_param('treasury_account_id'),
            'direction' => $request->get_param('direction'),
            'source' => $request->get_param('source'),
        ])]);
    }

    public function matchTransaction(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->matchTransaction(
            (int) $request['id'],
            $this->payload($request),
            get_current_user_id()
        ), 201);
    }

    public function createSettlement(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createSettlement($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function settlements(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->settlements([
            'provider_id' => $request->get_param('provider_id'),
            'treasury_account_id' => $request->get_param('treasury_account_id'),
        ])]);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->handleCallback(
            (string) $request['provider'],
            $request->get_body(),
            $this->headers($request),
            $this->systemActor()
        ));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new TreasuryDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function headers(WP_REST_Request $request): array
    {
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values) ? (string) reset($values) : (string) $values;
        }

        return $headers;
    }

    private function systemActor(): int
    {
        $actor = (int) get_option('rishe_system_user_id', 1);
        if ($actor < 1) {
            throw new TreasuryDomainException('A valid Rishe system user is required.');
        }

        return $actor;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $successStatus);
        } catch (TreasuryDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            return new WP_REST_Response(['error' => 'Unexpected treasury error.'], 500);
        }
    }
}
