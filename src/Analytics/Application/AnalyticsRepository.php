<?php

declare(strict_types=1);

namespace Rishe\Analytics\Application;

interface AnalyticsRepository
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createSource(array $data): array;

    /** @return list<array<string, mixed>> */
    public function sources(bool $activeOnly = false): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createCampaign(array $data): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function campaigns(array $filters = []): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function attributeOrder(int $orderId, array $data): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function recordPrice(array $data): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function priceHistory(array $filters = []): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createTarget(array $data): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function targets(array $filters = []): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function appendBusinessEvent(array $data): array;

    /** @param array<string, mixed> $auditEvent @return list<array<string, mixed>> */
    public function businessRowsFromAudit(array $auditEvent): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function events(array $filters = []): array;

    /** @return list<array<string, mixed>> */
    public function eventsAfterCursor(int $limit): array;

    /** @param array<string, mixed> $event */
    public function projectEvent(array $event): void;

    public function projectionCursor(): int;

    public function advanceProjectionCursor(int $eventId): void;

    /** @return array<string, int> */
    public function captureInventorySnapshot(string $date): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function executiveDashboard(array $filters): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function salesDashboard(array $filters): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function inventoryDashboard(array $filters): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function financeDashboard(array $filters): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function customerDashboard(array $filters): array;

    /** @return list<array<string, mixed>> */
    public function alertCandidates(string $now): array;

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    public function upsertAlert(array $candidate): array;

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function alerts(array $filters = []): array;

    /** @return array<string, mixed> */
    public function updateAlert(int $alertId, string $status, int $actorUserId): array;
}
