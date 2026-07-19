<?php

declare(strict_types=1);

namespace Rishe\Deployment\Infrastructure\WordPress;

use Rishe\Deployment\Application\CertificationService;
use Rishe\Deployment\Domain\BackupManifest;
use Rishe\Deployment\Domain\CertificationSummary;
use Rishe\Deployment\Infrastructure\WpBackupManager;
use Rishe\Deployment\Infrastructure\WpCertificationProbe;
use Rishe\Operations\Infrastructure\WordPress\OperationsServiceFactory;
use Rishe\Shared\Audit\AuditLogger;
use Throwable;

final class RisheCliRegistrar
{
    public function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('rishe diagnostics', [$this, 'diagnostics']);
        \WP_CLI::add_command('rishe certify', [$this, 'certify']);
        \WP_CLI::add_command('rishe queue enqueue', [$this, 'queueEnqueue']);
        \WP_CLI::add_command('rishe queue run', [$this, 'queueRun']);
        \WP_CLI::add_command('rishe queue recover', [$this, 'queueRecover']);
        \WP_CLI::add_command('rishe backup create', [$this, 'backupCreate']);
        \WP_CLI::add_command('rishe backup verify', [$this, 'backupVerify']);
        \WP_CLI::add_command('rishe backup restore', [$this, 'backupRestore']);
        \WP_CLI::add_command('rishe deploy record', [$this, 'deployRecord']);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function diagnostics(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $report = (new OperationsServiceFactory())->diagnostics()->report();
            $this->render($report, (string) ($assocArgs['format'] ?? 'json'));
            if (($assocArgs['strict'] ?? false) && (string) ($report['status'] ?? 'critical') !== 'ok') {
                \WP_CLI::halt(1);
            }
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function certify(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $environment = (string) ($assocArgs['environment'] ?? wp_get_environment_type());
            if (!in_array($environment, ['staging', 'production'], true)) {
                $environment = 'staging';
            }
            $factory = new OperationsServiceFactory();
            $service = new CertificationService(
                new WpCertificationProbe($factory->diagnostics()),
                new CertificationSummary(),
                new AuditLogger()
            );
            $report = $service->run($environment, $this->actor());
            update_option('rishe_last_certification_report', $report, false);
            update_option('rishe_last_certification_at', (string) $report['generated_at'], true);
            $this->render($report, (string) ($assocArgs['format'] ?? 'json'));
            $strict = filter_var($assocArgs['strict'] ?? false, FILTER_VALIDATE_BOOL);
            if (!(bool) $report['certifiable'] || ($strict && (string) $report['status'] !== 'pass')) {
                \WP_CLI::halt(1);
            }
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function queueEnqueue(array $args, array $assocArgs): void
    {
        $this->execute(function () use ($args, $assocArgs): void {
            $jobType = (string) ($args[0] ?? '');
            if ($jobType === '') {
                throw new \InvalidArgumentException('Job type is required.');
            }
            $payload = [];
            if (isset($assocArgs['payload'])) {
                $decoded = json_decode((string) $assocArgs['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('Job payload must decode to a JSON object.');
                }
                $payload = $decoded;
            }
            $service = (new OperationsServiceFactory())->operations();
            $result = $service->enqueue([
                'job_type' => $jobType,
                'aggregate_type' => (string) ($assocArgs['aggregate-type'] ?? 'system'),
                'aggregate_id' => (string) ($assocArgs['aggregate-id'] ?? 'health'),
                'idempotency_key' => (string) ($assocArgs['key'] ?? wp_generate_uuid4()),
                'payload' => $payload,
                'priority' => (int) ($assocArgs['priority'] ?? 100),
                'max_attempts' => (int) ($assocArgs['max-attempts'] ?? 3),
            ], $this->actor());
            $this->render($result, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function queueRun(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $limit = max(1, min(500, (int) ($assocArgs['limit'] ?? 25)));
            $service = (new OperationsServiceFactory())->operations();
            $jobs = array_merge($service->jobs(['status' => 'pending']), $service->jobs(['status' => 'retry_wait']));
            usort($jobs, static function (array $left, array $right): int {
                $priority = (int) $left['priority'] <=> (int) $right['priority'];
                return $priority !== 0 ? $priority : strcmp((string) $left['scheduled_at'], (string) $right['scheduled_at']);
            });
            $counts = ['selected' => 0, 'completed' => 0, 'retry_wait' => 0, 'failed' => 0, 'skipped' => 0];
            foreach (array_slice($jobs, 0, $limit) as $job) {
                ++$counts['selected'];
                $result = $service->execute((int) $job['id']);
                $status = (string) ($result['status'] ?? 'skipped');
                if (array_key_exists($status, $counts)) {
                    ++$counts[$status];
                } else {
                    ++$counts['skipped'];
                }
            }
            $this->render($counts, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function queueRecover(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $result = (new OperationsServiceFactory())->operations()->recoverStaleJobs(
                max(60, (int) ($assocArgs['timeout'] ?? 900))
            );
            $this->render($result, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function backupCreate(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $result = $this->backups()->create(isset($assocArgs['output']) ? (string) $assocArgs['output'] : null);
            $this->render($result, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function backupVerify(array $args, array $assocArgs): void
    {
        $this->execute(function () use ($args, $assocArgs): void {
            $archive = (string) ($args[0] ?? $assocArgs['archive'] ?? '');
            if ($archive === '') {
                throw new \InvalidArgumentException('Backup archive path is required.');
            }
            $this->render($this->backups()->verify($archive), (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function backupRestore(array $args, array $assocArgs): void
    {
        $this->execute(function () use ($args, $assocArgs): void {
            $archive = (string) ($args[0] ?? $assocArgs['archive'] ?? '');
            $confirmation = (string) ($assocArgs['confirm'] ?? '');
            $allowProduction = filter_var($assocArgs['allow-production'] ?? false, FILTER_VALIDATE_BOOL);
            $result = $this->backups()->restore(
                $archive,
                $confirmation,
                $allowProduction,
                $this->actor()
            );
            $this->render($result, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function deployRecord(array $args, array $assocArgs): void
    {
        unset($args);
        $this->execute(function () use ($assocArgs): void {
            $environment = (string) ($assocArgs['environment'] ?? wp_get_environment_type());
            $version = (string) ($assocArgs['version'] ?? RISHE_VERSION);
            $sha256 = strtolower((string) ($assocArgs['sha256'] ?? ''));
            $status = strtolower((string) ($assocArgs['status'] ?? 'succeeded'));
            if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                throw new \InvalidArgumentException('Deployment artifact SHA-256 is required.');
            }
            if (!in_array($status, ['started', 'succeeded', 'rolled_back', 'failed'], true)) {
                throw new \InvalidArgumentException('Deployment status is invalid.');
            }
            $record = [
                'environment' => $environment,
                'version' => $version,
                'sha256' => $sha256,
                'status' => $status,
                'recorded_at' => gmdate('c'),
                'actor_user_id' => $this->actor(),
            ];
            update_option('rishe_last_deployment', $record, false);
            (new AuditLogger())->record('deployment.release.' . $status, 'release', $version, $record);
            $this->render($record, (string) ($assocArgs['format'] ?? 'json'));
        });
    }

    private function backups(): WpBackupManager
    {
        return new WpBackupManager(
            (new OperationsServiceFactory())->configuration(),
            new BackupManifest(),
            new AuditLogger()
        );
    }

    private function actor(): int
    {
        return max(1, (int) get_option('rishe_system_user_id', 1));
    }

    /** @param array<string, mixed> $data */
    private function render(array $data, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        foreach ($data as $key => $value) {
            \WP_CLI::line(sprintf('%s: %s', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
        }
    }

    private function execute(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            \WP_CLI::error($exception->getMessage());
        }
    }
}
