<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Tax\Application\TaxGateway;

final class InMemoryTaxGateway implements TaxGateway
{
    public array $submitted = [];
    public string $status = 'accepted';

    public function submit(array $profile, array $invoice): array
    {
        $this->submitted[] = $invoice;

        return [
            'status' => $this->status,
            'reference_number' => 'REF-' . $invoice['id'],
            'uid' => 'UID-' . $invoice['id'],
        ];
    }

    public function inquire(array $profile, string $referenceNumber): array
    {
        return ['status' => $this->status, 'reference_number' => $referenceNumber];
    }
}
