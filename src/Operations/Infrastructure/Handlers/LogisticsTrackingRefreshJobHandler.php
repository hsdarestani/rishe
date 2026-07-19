<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\Handlers;

use Rishe\Logistics\Application\LogisticsService;
use Rishe\Operations\Application\JobHandler;
use Rishe\Operations\Domain\Exception\OperationsDomainException;

final class LogisticsTrackingRefreshJobHandler implements JobHandler
{
    public function __construct(private LogisticsService $service)
    {
    }

    public function type(): string
    {
        return 'logistics.tracking.refresh';
    }

    public function handle(array $job): array
    {
        $shipmentId = $this->positiveId($job['payload']['shipment_id'] ?? $job['aggregate_id'] ?? null);
        $shipment = $this->service->refreshTracking($shipmentId, (int) $job['created_by']);

        return [
            'shipment_id' => (int) $shipment['id'],
            'status' => (string) $shipment['status'],
            'tracking_number' => $shipment['tracking_number'] ?? null,
        ];
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new OperationsDomainException('Tracking refresh job requires a valid shipment id.');
        }

        return (int) $id;
    }
}
