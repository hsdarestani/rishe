<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Inventory\Application\InventoryService;
use Rishe\Inventory\Domain\FifoAllocator;
use Rishe\Inventory\Infrastructure\WpdbInventoryRepository;
use Rishe\Sales\Application\SalesService;
use Rishe\Sales\Domain\Exception\SalesDomainException;
use Rishe\Sales\Domain\MobileNormalizer;
use Rishe\Sales\Domain\OrderTotalCalculator;
use Rishe\Sales\Infrastructure\WooCommerceOrderMapper;
use Rishe\Sales\Infrastructure\WpAccountingGateway;
use Rishe\Sales\Infrastructure\WpdbSalesRepository;
use Rishe\Sales\Infrastructure\WpInventoryGateway;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SalesRestApi
{
    private SalesService $service;
    private WooCommerceOrderMapper $wooCommerce;

    public function __construct(?SalesService $service = null, ?WooCommerceOrderMapper $wooCommerce = null)
    {
        if ($service !== null && $wooCommerce !== null) {
            $this->service = $service;
            $this->wooCommerce = $wooCommerce;

            return;
        }

        $transactions = new TransactionManager();
        $audit = new AuditLogger();
        $repository = new WpdbSalesRepository();
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
        $this->service = $service ?? new SalesService(
            $repository,
            new WpInventoryGateway($inventory),
            new WpAccountingGateway($accounting),
            $transactions,
            $audit,
            new MobileNormalizer(),
            new OrderTotalCalculator()
        );
        $this->wooCommerce = $wooCommerce ?? new WooCommerceOrderMapper($repository);
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $sales = static fn (): bool => current_user_can('rishe_manage_sales');
        $crm = static fn (): bool => current_user_can('rishe_manage_crm');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/crm/customers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upsertCustomer'],
            'permission_callback' => $crm,
        ]);
        register_rest_route('rishe/v1', '/crm/customers/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getCustomer'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/sales/channel-prices', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createChannelPrice'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/sales/promotions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createPromotion'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/sales/orders', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createOrder'],
                'permission_callback' => $sales,
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listOrders'],
                'permission_callback' => $report,
            ],
        ]);
        register_rest_route('rishe/v1', '/sales/orders/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getOrder'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/sales/orders/(?P<id>\d+)/payments', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'capturePayment'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/sales/orders/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancelOrder'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/sales/orders/(?P<id>\d+)/complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'completeOrder'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/sales/orders/(?P<id>\d+)/accounting/retry', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'retryAccounting'],
            'permission_callback' => $sales,
        ]);
        register_rest_route('rishe/v1', '/integrations/woocommerce/orders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'woocommerceOrder'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('rishe/v1', '/integrations/payments/(?P<provider>[a-z0-9._-]+)/callback', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'paymentCallback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function upsertCustomer(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->upsertCustomer($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function getCustomer(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->customer((int) $request['id']));
    }

    public function createChannelPrice(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createChannelPrice($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function createPromotion(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'id' => $this->service->createPromotion($this->payload($request), get_current_user_id()),
        ], 201);
    }

    public function createOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->service->createOrder($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function listOrders(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->orders([
            'status' => $request->get_param('status'),
            'channel' => $request->get_param('channel'),
            'customer_id' => $request->get_param('customer_id'),
            'from' => $request->get_param('from'),
            'to' => $request->get_param('to'),
        ])]);
    }

    public function getOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->order((int) $request['id']));
    }

    public function capturePayment(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->capturePayment(
            (int) $request['id'],
            $this->payload($request),
            get_current_user_id()
        ));
    }

    public function cancelOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request, true);
            $this->service->cancelOrder(
                (int) $request['id'],
                get_current_user_id(),
                (string) ($payload['reason'] ?? '')
            );

            return ['id' => (int) $request['id'], 'status' => 'cancelled'];
        });
    }

    public function completeOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $this->service->completeOrder((int) $request['id'], get_current_user_id());

            return ['id' => (int) $request['id'], 'status' => 'completed'];
        });
    }

    public function retryAccounting(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->retryAccounting(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function woocommerceOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $body = $request->get_body();
            $this->assertWooCommerceSignature($body, (string) $request->get_header('x-wc-webhook-signature'));
            $mapped = $this->wooCommerce->map($this->payload($request));
            $actor = $this->systemActor();
            $order = $this->service->createOrder($mapped['order'], $actor);
            if ($mapped['cancelled']) {
                if ((string) $order['status'] !== 'cancelled') {
                    $this->service->cancelOrder((int) $order['id'], $actor, 'WooCommerce order cancelled');
                }

                return $this->service->order((int) $order['id']);
            }
            if ($mapped['payment'] !== null) {
                $order = $this->service->capturePayment((int) $order['id'], $mapped['payment'], $actor);
            }
            if ($mapped['completed'] && (string) $order['status'] !== 'completed') {
                $this->service->completeOrder((int) $order['id'], $actor);

                return $this->service->order((int) $order['id']);
            }

            return $order;
        }, 201);
    }

    public function paymentCallback(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $provider = (string) $request['provider'];
            $body = $request->get_body();
            $this->assertPaymentSignature($provider, $body, (string) $request->get_header('x-rishe-signature'));
            $payload = $this->payload($request);
            if (strtolower((string) ($payload['status'] ?? '')) !== 'captured') {
                throw new SalesDomainException('Only captured payment callbacks are accepted.');
            }

            return $this->service->capturePaymentByKey(
                (string) ($payload['order_key'] ?? ''),
                [
                    'provider' => $provider,
                    'external_payment_id' => $payload['external_payment_id'] ?? null,
                    'amount_irr' => $payload['amount_irr'] ?? null,
                    'raw_hash' => hash('sha256', $body),
                ],
                $this->systemActor()
            );
        });
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request, bool $allowEmpty = false): array
    {
        $payload = $request->get_json_params();
        if ($allowEmpty && ($payload === null || $payload === [])) {
            return [];
        }
        if (!is_array($payload)) {
            throw new SalesDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    private function assertWooCommerceSignature(string $body, string $signature): void
    {
        $secret = (string) get_option('rishe_woocommerce_webhook_secret', '');
        if ($secret === '' || $signature === '') {
            throw new SalesDomainException('WooCommerce webhook secret or signature is missing.');
        }
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expected, trim($signature))) {
            throw new SalesDomainException('WooCommerce webhook signature is invalid.');
        }
    }

    private function assertPaymentSignature(string $provider, string $body, string $signature): void
    {
        $secrets = get_option('rishe_payment_webhook_secrets', []);
        $secret = is_array($secrets) ? (string) ($secrets[$provider] ?? '') : '';
        if ($secret === '' || $signature === '') {
            throw new SalesDomainException('Payment webhook secret or signature is missing.');
        }
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, strtolower(trim($signature)))) {
            throw new SalesDomainException('Payment webhook signature is invalid.');
        }
    }

    private function systemActor(): int
    {
        $actor = (int) get_option('rishe_system_user_id', 1);
        if ($actor < 1) {
            throw new SalesDomainException('A valid Rishe system user must be configured.');
        }

        return $actor;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $successStatus);
        } catch (SalesDomainException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_sales_validation',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_sales_conflict',
                'message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $exception) {
            do_action('rishe/sales/error', $exception);

            return new WP_REST_Response([
                'code' => 'rishe_sales_error',
                'message' => 'An unexpected sales or CRM error occurred.',
            ], 500);
        }
    }
}
