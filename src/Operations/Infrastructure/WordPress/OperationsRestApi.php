<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\WordPress;

use Rishe\Operations\Application\ConfigurationManager;
use Rishe\Operations\Application\DiagnosticsService;
use Rishe\Operations\Application\OperationsService;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OperationsRestApi
{
    private OperationsService $operations;
    private DiagnosticsService $diagnostics;
    private ConfigurationManager $configuration;

    public function __construct(
        ?OperationsService $operations = null,
        ?DiagnosticsService $diagnostics = null,
        ?ConfigurationManager $configuration = null
    ) {
        $factory = new OperationsServiceFactory();
        $this->operations = $operations ?? $factory->operations();
        $this->diagnostics = $diagnostics ?? $factory->diagnostics();
        $this->configuration = $configuration ?? $factory->configuration();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_operations');
        $report = static fn (): bool => current_user_can('rishe_view_reports');

        register_rest_route('rishe/v1', '/operations/dashboard', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'dashboard'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'diagnostics'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/operations/jobs', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'jobs'],
                'permission_callback' => $manage,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'enqueue'],
                'permission_callback' => $manage,
            ],
        ]);
        register_rest_route('rishe/v1', '/operations/jobs/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'job'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/jobs/(?P<id>\d+)/retry', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'retry'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/jobs/(?P<id>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/incidents', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'incidents'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/incidents/(?P<id>\d+)/(?P<status>acknowledged|resolved|open)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'updateIncident'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/configuration/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'exportConfiguration'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/operations/configuration/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'importConfiguration'],
            'permission_callback' => $manage,
        ]);
    }

    public function dashboard(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->operations->dashboard() + [
            'diagnostics' => $this->diagnostics->report(),
        ]);
    }

    public function diagnostics(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->diagnostics->report());
    }

    public function jobs(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => [
            'rows' => $this->operations->jobs([
                'status' => $request->get_param('status'),
                'job_type' => $request->get_param('job_type'),
                'aggregate_type' => $request->get_param('aggregate_type'),
            ]),
            'job_types' => $this->operations->jobTypes(),
        ]);
    }

    public function enqueue(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(
            fn (): array => $this->operations->enqueue($this->payload($request), get_current_user_id()),
            201
        );
    }

    public function job(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->operations->job((int) $request['id']));
    }

    public function retry(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->operations->retry(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->operations->cancel(
            (int) $request['id'],
            get_current_user_id()
        ));
    }

    public function incidents(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->operations->incidents([
            'status' => $request->get_param('status'),
            'severity' => $request->get_param('severity'),
            'source' => $request->get_param('source'),
        ])]);
    }

    public function updateIncident(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->operations->updateIncident(
            (int) $request['id'],
            (string) $request['status'],
            get_current_user_id()
        ));
    }

    public function exportConfiguration(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->execute(fn (): array => $this->configuration->export());
    }

    public function importConfiguration(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(function () use ($request): array {
            $payload = $this->payload($request);
            $package = $payload['package'] ?? null;
            if (!is_array($package)) {
                throw new OperationsDomainException('Configuration package is required.');
            }
            $mode = strtolower(trim((string) ($payload['mode'] ?? 'preview')));
            if ($mode === 'preview') {
                return $this->configuration->preview($package) + ['applied' => false];
            }
            if ($mode !== 'apply') {
                throw new OperationsDomainException('Configuration import mode is invalid.');
            }

            return $this->configuration->apply(
                $package,
                (string) ($payload['checksum'] ?? ''),
                get_current_user_id()
            );
        });
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new OperationsDomainException('A JSON request body is required.');
        }

        return $payload;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (OperationsDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            $code = str_contains(strtolower($exception->getMessage()), 'not found') ? 404 : 500;

            return new WP_REST_Response(['error' => $exception->getMessage()], $code);
        } catch (Throwable $exception) {
            return new WP_REST_Response(['error' => 'Unexpected operations error.'], 500);
        }
    }
}
