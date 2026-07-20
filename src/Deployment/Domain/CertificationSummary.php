<?php

declare(strict_types=1);

namespace Rishe\Deployment\Domain;

final class CertificationSummary
{
    /**
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    public function summarize(array $checks): array
    {
        $rank = ['pass' => 0, 'warn' => 1, 'fail' => 2];
        $status = 'pass';
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        $normalized = [];

        foreach ($checks as $check) {
            $checkStatus = strtolower((string) ($check['status'] ?? 'fail'));
            if (!array_key_exists($checkStatus, $rank)) {
                $checkStatus = 'fail';
            }
            ++$counts[$checkStatus];
            if ($rank[$checkStatus] > $rank[$status]) {
                $status = $checkStatus;
            }
            $normalized[] = [
                'code' => (string) ($check['code'] ?? 'unknown'),
                'status' => $checkStatus,
                'message' => (string) ($check['message'] ?? ''),
                'context' => is_array($check['context'] ?? null) ? $check['context'] : [],
            ];
        }

        return [
            'status' => $status,
            'certifiable' => $status !== 'fail',
            'counts' => $counts,
            'checks' => $normalized,
        ];
    }
}
