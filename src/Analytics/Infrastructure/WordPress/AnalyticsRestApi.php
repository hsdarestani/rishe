<?php

declare(strict_types=1);

namespace Rishe\Analytics\Infrastructure\WordPress;

use Rishe\Analytics\Application\AnalyticsService;
use Rishe\Analytics\Domain\Exception\AnalyticsDomainException;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AnalyticsRestApi
{
    private AnalyticsService $service;

    public function __construct(?AnalyticsService $service = null)
    {
        $this->service = $service ?? (new AnalyticsServiceFactory())->service();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $manage = static fn (): bool => current_user_can('rishe_manage_analytics');
        $report = static fn (): bool => current_user_can('rishe_view_reports');
        register_rest_route('rishe/v1', '/analytics/sources', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'sources'], 'permission_callback' => $report],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createSource'], 'permission_callback' => $manage],
        ]);
        register_rest_route('rishe/v1', '/analytics/campaigns', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'campaigns'], 'permission_callback' => $report],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createCampaign'], 'permission_callback' => $manage],
        ]);
        register_rest_route('rishe/v1', '/analytics/orders/(?P<id>\d+)/attribution', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'attributeOrder'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/analytics/prices', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'prices'], 'permission_callback' => $report],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'recordPrice'], 'permission_callback' => $manage],
        ]);
        register_rest_route('rishe/v1', '/analytics/targets', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'targets'], 'permission_callback' => $report],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'createTarget'], 'permission_callback' => $manage],
        ]);
        register_rest_route('rishe/v1', '/analytics/events', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'events'], 'permission_callback' => $report],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'recordEvent'], 'permission_callback' => $manage],
        ]);
        register_rest_route('rishe/v1', '/analytics/project', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'project'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/analytics/snapshot', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'snapshot'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/analytics/alerts/evaluate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'evaluateAlerts'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/analytics/alerts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'alerts'],
            'permission_callback' => $report,
        ]);
        register_rest_route('rishe/v1', '/analytics/alerts/(?P<id>\d+)/(?P<status>open|acknowledged|resolved)', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'updateAlert'],
            'permission_callback' => $manage,
        ]);
        register_rest_route('rishe/v1', '/analytics/dashboard/(?P<type>executive|sales|inventory|finance|customers)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'dashboard'],
            'permission_callback' => $report,
        ]);
    }

    public function sources(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->sources((bool) $request->get_param('active_only'))]);
    }

    public function createSource(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->createSource($this->payload($request), get_current_user_id()), 201);
    }

    public function campaigns(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->campaigns($this->query($request))]);
    }

    public function createCampaign(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->createCampaign($this->payload($request), get_current_user_id()), 201);
    }

    public function attributeOrder(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->attributeOrder(
            (int) $request['id'],
            $this->payload($request),
            get_current_user_id()
        ), 201);
    }

    public function prices(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->priceHistory($this->query($request))]);
    }

    public function recordPrice(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->recordPrice($this->payload($request), get_current_user_id()), 201);
    }

    public function targets(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->targets($this->query($request))]);
    }

    public function createTarget(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->createTarget($this->payload($request), get_current_user_id()), 201);
    }

    public function events(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->events($this->query($request))]);
    }

    public function recordEvent(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->recordBusinessEvent($this->payload($request), get_current_user_id()), 201);
    }

    public function project(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->project(max(1, (int) ($this->payload($request)['limit'] ?? 500))));
    }

    public function snapshot(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->snapshot((string) ($this->payload($request)['date'] ?? '')));
    }

    public function evaluateAlerts(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        return $this->execute(fn (): array => $this->service->evaluateAlerts());
    }

    public function alerts(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => ['rows' => $this->service->alerts($this->query($request))]);
    }

    public function updateAlert(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->updateAlert(
            (int) $request['id'],
            (string) $request['status'],
            get_current_user_id()
        ));
    }

    public function dashboard(WP_REST_Request $request): WP_REST_Response
    {
        return $this->execute(fn (): array => $this->service->dashboard((string) $request['type'], $this->query($request)));
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw new AnalyticsDomainException('A JSON request body is required.');
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    private function query(WP_REST_Request $request): array
    {
        return array_filter($request->get_params(), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param callable(): array<string, mixed> $operation */
    private function execute(callable $operation, int $status = 200): WP_REST_Response
    {
        try {
            return new WP_REST_Response($operation(), $status);
        } catch (AnalyticsDomainException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            $code = str_contains(strtolower($exception->getMessage()), 'not found') ? 404 : 500;
            return new WP_REST_Response(['error' => $exception->getMessage()], $code);
        } catch (Throwable) {
            return new WP_REST_Response(['error' => 'Unexpected analytics error.'], 500);
        }
    }
}
