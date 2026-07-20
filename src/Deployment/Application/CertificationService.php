<?php

declare(strict_types=1);

namespace Rishe\Deployment\Application;

use Rishe\Deployment\Domain\CertificationSummary;
use Rishe\Shared\Audit\AuditRecorder;
use InvalidArgumentException;

final class CertificationService
{
    public function __construct(
        private CertificationProbe $probe,
        private CertificationSummary $summary,
        private AuditRecorder $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $environment, int $actorUserId = 0): array
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['staging', 'production'], true)) {
            throw new InvalidArgumentException('Certification environment must be staging or production.');
        }

        $report = $this->summary->summarize($this->probe->checks($environment));
        $report['environment'] = $environment;
        $report['plugin_version'] = RISHE_VERSION;
        $report['database_version'] = RISHE_DB_VERSION;
        $report['generated_at'] = gmdate('c');
        $report['report_hash'] = hash(
            'sha256',
            json_encode($report['checks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->audit->record('deployment.certification.completed', 'environment', $environment, [
            'actor_user_id' => $actorUserId,
            'status' => $report['status'],
            'certifiable' => $report['certifiable'],
            'counts' => $report['counts'],
            'report_hash' => $report['report_hash'],
        ]);

        return $report;
    }
}
