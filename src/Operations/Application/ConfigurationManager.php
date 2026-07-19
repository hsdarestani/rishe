<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\ConfigurationPackage;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Shared\Audit\AuditRecorder;
use Throwable;

final class ConfigurationManager
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'rishe_loyalty_policy',
        'rishe_sales_accounting_mapping',
        'rishe_procurement_accounting_mapping',
        'rishe_b2b_accounting_mapping',
        'rishe_logistics_accounting_mapping',
        'rishe_woocommerce_warehouse_id',
        'rishe_system_user_id',
    ];

    public function __construct(
        private ConfigurationStore $store,
        private ConfigurationPackage $packages,
        private AuditRecorder $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        $options = [];
        foreach (self::ALLOWED_KEYS as $key) {
            $options[$key] = $this->store->get($key);
        }
        $package = $this->packages->build($options, RISHE_VERSION, gmdate('c'));
        $this->audit->record('operations.configuration.exported', 'configuration', $package['checksum'], [
            'keys' => self::ALLOWED_KEYS,
        ]);

        return $package;
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    public function preview(array $package): array
    {
        $options = $this->packages->validate($package);
        $this->assertAllowedKeys(array_keys($options));
        $changes = [];
        foreach ($options as $key => $value) {
            $current = $this->store->get($key);
            if ($current === $value) {
                continue;
            }
            $changes[] = [
                'key' => $key,
                'current' => $current,
                'incoming' => $value,
            ];
        }

        return [
            'checksum' => $this->packages->checksum($options),
            'changes' => $changes,
            'change_count' => count($changes),
        ];
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    public function apply(array $package, string $expectedChecksum, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new OperationsDomainException('Configuration import requires an authenticated actor.');
        }
        $preview = $this->preview($package);
        if (!hash_equals((string) $preview['checksum'], strtolower(trim($expectedChecksum)))) {
            throw new OperationsDomainException('Configuration import checksum confirmation does not match.');
        }
        $options = $this->packages->validate($package);
        $before = [];
        try {
            foreach ($preview['changes'] as $change) {
                $key = (string) $change['key'];
                $before[$key] = $this->store->get($key);
                $this->store->set($key, $options[$key]);
            }
        } catch (Throwable $exception) {
            foreach ($before as $key => $value) {
                try {
                    $this->store->set($key, $value);
                } catch (Throwable) {
                    // Best-effort rollback. The incident is surfaced by the original exception.
                }
            }
            throw new OperationsDomainException('Configuration import failed and was rolled back.', 0, $exception);
        }
        $this->audit->record('operations.configuration.imported', 'configuration', $preview['checksum'], [
            'actor_user_id' => $actorUserId,
            'changed_keys' => array_map(
                static fn (array $change): string => (string) $change['key'],
                $preview['changes']
            ),
        ]);

        return $preview + ['applied' => true];
    }

    /** @param list<string> $keys */
    private function assertAllowedKeys(array $keys): void
    {
        $unknown = array_values(array_diff($keys, self::ALLOWED_KEYS));
        if ($unknown !== []) {
            throw new OperationsDomainException('Configuration package contains unsupported or secret keys.');
        }
    }
}
