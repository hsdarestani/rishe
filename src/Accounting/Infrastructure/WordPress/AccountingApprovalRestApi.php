<?php

declare(strict_types=1);

namespace Rishe\Accounting\Infrastructure\WordPress;

use Rishe\Accounting\Application\AccountingReviewService;
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

final class AccountingApprovalRestApi
{
    private AccountingReviewService $reviews;

    public function __construct(?AccountingReviewService $reviews = null)
    {
        $accounting = new AccountingService(
            new WpdbAccountingRepository(),
            new TransactionManager(),
            new AuditLogger()
        );
        $this->reviews = $reviews ?? new AccountingReviewService($accounting);
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_accounting') || current_user_can('manage_rishe');

        register_rest_route('rishe/v1', '/accounting/review-summary', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'summary'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/review-queue', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'queue'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/review-queue/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'voucher'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/review-queue/(?P<id>\d+)/approve', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'approve'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/review-queue/(?P<id>\d+)/reject', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reject'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/document-templates', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'templates'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/accounting/generated-documents', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createGeneratedDocument'],
            'permission_callback' => $manage,
        ]);
    }

    public function summary(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->reviews->summary());
    }

    public function queue(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->reviews->queue(
                sanitize_key((string) ($request->get_param('status') ?: 'pending')),
                sanitize_key((string) $request->get_param('source'))
            ),
        ]);
    }

    public function voucher(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->reviews->voucher((int) $request['id']));
    }

    public function approve(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->reviews->approve(
            (int) $request['id'],
            get_current_user_id(),
            sanitize_textarea_field((string) ($this->payload($request)['note'] ?? ''))
        ));
    }

    public function reject(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->reviews->reject(
            (int) $request['id'],
            get_current_user_id(),
            sanitize_textarea_field((string) ($this->payload($request)['note'] ?? ''))
        ));
    }

    public function templates(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => ['templates' => $this->reviews->templates()]);
    }

    public function createGeneratedDocument(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->reviews->createGeneratedDocument($this->payload($request), get_current_user_id()),
            201
        );
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();

        return is_array($payload) ? $payload : [];
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (AccountingDomainException|RuntimeException $exception) {
            return new WP_REST_Response([
                'code' => 'rishe_accounting_review_error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            error_log('[Rishe accounting review] ' . $exception->getMessage());

            return new WP_REST_Response([
                'code' => 'rishe_accounting_review_unexpected',
                'message' => 'خطای غیرمنتظره در کارتابل حسابداری رخ داد.',
            ], 500);
        }
    }
}
