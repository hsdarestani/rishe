<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure\Handlers;

use Rishe\Operations\Application\JobHandler;
use Rishe\Operations\Domain\Exception\OperationsDomainException;
use Rishe\Tax\Application\TaxService;

final class TaxSubmitJobHandler implements JobHandler
{
    public function __construct(private TaxService $service)
    {
    }

    public function type(): string
    {
        return 'tax.submit';
    }

    public function handle(array $job): array
    {
        $invoiceId = $this->positiveId($job['payload']['invoice_id'] ?? $job['aggregate_id'] ?? null);
        $invoice = $this->service->submit($invoiceId, (int) $job['created_by']);

        return [
            'invoice_id' => (int) $invoice['id'],
            'status' => (string) $invoice['status'],
            'reference_number' => $invoice['reference_number'] ?? null,
        ];
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new OperationsDomainException('Tax submission job requires a valid invoice id.');
        }

        return (int) $id;
    }
}
