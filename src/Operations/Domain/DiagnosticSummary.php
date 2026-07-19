<?php

declare(strict_types=1);

namespace Rishe\Operations\Domain;

final class DiagnosticSummary
{
    /** @param list<array<string, mixed>> $checks @return array<string, mixed> */
    public function summarize(array $checks): array
    {
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        $status = 'ok';
        $counts = ['ok' => 0, 'warning' => 0, 'critical' => 0];

        foreach ($checks as $check) {
            $checkStatus = (string) ($check['status'] ?? 'critical');
            if (!array_key_exists($checkStatus, $rank)) {
                $checkStatus = 'critical';
            }
            ++$counts[$checkStatus];
            if ($rank[$checkStatus] > $rank[$status]) {
                $status = $checkStatus;
            }
        }

        return [
            'status' => $status,
            'counts' => $counts,
            'checks' => $checks,
        ];
    }
}
