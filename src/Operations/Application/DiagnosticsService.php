<?php

declare(strict_types=1);

namespace Rishe\Operations\Application;

use Rishe\Operations\Domain\DiagnosticSummary;

final class DiagnosticsService
{
    public function __construct(
        private SystemProbe $probe,
        private OperationsRepository $repository,
        private DiagnosticSummary $summary
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $report = $this->summary->summarize($this->probe->checks());
        $report['metrics'] = $this->repository->metrics();
        $report['generated_at'] = gmdate('c');

        return $report;
    }
}
