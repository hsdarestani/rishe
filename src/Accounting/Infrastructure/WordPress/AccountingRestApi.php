<?php

declare(strict_types=1);

namespace Rishe\Accounting\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingService;
use Rishe\Accounting\Domain\Exception\AccountingDomainException;
use Rishe\Accounting\Infrastructure\WpdbAccountingRepository;
use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AccountingRestApi
{
    private AccountingService $service;

    public function __construct(?AccountingService $service = null)
    {
        $this->service = $service ?? new AccountingService(
            new WpdbAccountingRepository(),
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
        $manage = static fn (): bool => current_user_can('rishe_manage_accounting');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/accounting/account-groups', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createAccountGroup'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/general-ledgers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createGeneralLedger'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/subsidiary-ledgers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createSubsidiaryLedger'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/floating-details', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createFloatingDetail'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/chart', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'chart'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/accounting/vouchers', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createVoucher'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/vouchers/(?P<id>\d+)/post', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'postVoucher'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/vouchers/(?P<id>\d+)/reverse', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reverseVoucher'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/trial-balance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'trialBalance'],
            'permission_callback' => $report,
        ]);
    }

    public function createAccountGroup(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['id' => $this->service->createAccountGroup($this->payload($request))], 201);
    }

    public function createGeneralLedger(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['id' => $this->service->createGeneralLedger($this->payload($request))], 201);
    }

    public function createSubsidiaryLedger(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => ['id' => $this->service->createSubsidiaryLedger($this->payload($request))],
            201
        );
    }

    public function createFloatingDetail(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['id' => $this->service->createFloatingDetail($this->payload($request))], 201);
    }

    public function chart(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->service->chart());
    }

    public function createVoucher(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);
            $lines = $payload['lines'] ?? [];
            if (!is_array($lines)) {
                throw new AccountingDomainException('Voucher lines must be an array.');
            }

            $id = $this->service->createDraftVoucher(
                (int) ($payload['fiscal_year'] ?? 0),
                (string) ($payload['voucher_date'] ?? ''),
                (string) ($payload['description'] ?? ''),
                array_values($lines),
                isset($payload['correlation_id']) ? (string) $payload['correlation_id'] : null
            );

            return ['id' => $id, 'status' => 'draft'];
        }, 201);
    }

    public function postVoucher(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $number = $this->service->postVoucher((int) $request['id'], get_current_user_id());

            return ['id' => (int) $request['id'], 'status' => 'posted', 'voucher_number' => $number];
        });
    }

    public function reverseVoucher(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);
            $reversalId = $this->service->reverseVoucher(
                (int) $request['id'],
                (int) ($payload['fiscal_year'] ?? 0),
                (string) ($payload['voucher_date'] ?? ''),
                (string) ($payload['description'] ?? ''),
                get_current_user_id()
            );

            return ['original_id' => (int) $request['id'], 'reversal_id' => $reversalId, 'status' => 'reversed'];
        }, 201);
    }

    public function trialBalance(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'from' => (string) $request->get_param('from'),
            'to' => (string) $request->get_param('to'),
            'rows' => $this->service->trialBalance(
                (string) $request->get_param('from'),
                (string) $request->get_param('to')
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new AccountingDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $successStatus);
        } catch (AccountingDomainException $exception) {
            return new WP_REST_Response(['code' => 'rishe_accounting_validation', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return new WP_REST_Response(['code' => 'rishe_accounting_conflict', 'message' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            do_action('rishe/accounting/error', $exception);

            return new WP_REST_Response(
                ['code' => 'rishe_accounting_error', 'message' => 'An unexpected accounting error occurred.'],
                500
            );
        }
    }
}
