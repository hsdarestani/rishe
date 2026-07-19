<?php

declare(strict_types=1);

namespace Rishe\Procurement\Application;

use RuntimeException;

trait ProcurementCatalogOperations
{
    /** @param array<string, mixed> $data @return array{id: int, created: bool} */
    public function upsertSupplier(array $data, int $actorUserId): array
    {
        $payload = [
            'code' => $this->requiredCode($data['code'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'mobile' => $this->nullableText($data['mobile'] ?? null, 20),
            'email' => $this->nullableText($data['email'] ?? null, 191),
            'national_id' => $this->nullableText($data['national_id'] ?? null, 30),
            'economic_code' => $this->nullableText($data['economic_code'] ?? null, 30),
            'tax_id' => $this->nullableText($data['tax_id'] ?? null, 50),
            'iban' => $this->nullableText($data['iban'] ?? null, 34),
            'payment_terms_days' => $this->nonNegativeMoney($data['payment_terms_days'] ?? 0, 'payment_terms_days'),
            'credit_limit_irr' => $this->nonNegativeMoney($data['credit_limit_irr'] ?? 0, 'credit_limit_irr'),
            'payable_subsidiary_ledger_id' => $this->optionalPositiveId(
                $data['payable_subsidiary_ledger_id'] ?? null
            ),
            'floating_detail_id' => $this->optionalPositiveId($data['floating_detail_id'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->upsertSupplier($payload);
            $this->audit->record(
                $result['created'] ? 'procurement.supplier.created' : 'procurement.supplier.updated',
                'supplier',
                (string) $result['id'],
                ['code' => $payload['code'], 'name' => $payload['name']]
            );

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function supplier(int $supplierId): array
    {
        $supplier = $this->repository->supplier($this->positiveId($supplierId, 'supplier_id'));
        if ($supplier === null) {
            throw new RuntimeException('Supplier not found.');
        }

        return $supplier;
    }

    /** @return list<array<string, mixed>> */
    public function suppliers(array $filters = []): array
    {
        return $this->repository->suppliers([
            'is_active' => isset($filters['is_active']) ? (int) (bool) $filters['is_active'] : null,
        ]);
    }
}
