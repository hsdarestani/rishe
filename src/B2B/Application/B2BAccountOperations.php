<?php

declare(strict_types=1);

namespace Rishe\B2B\Application;

use Rishe\B2B\Domain\Exception\B2BDomainException;
use RuntimeException;

trait B2BAccountOperations
{
    /** @param array<string, mixed> $data @return array{id: int, created: bool} */
    public function upsertAccount(array $data, int $actorUserId): array
    {
        $customerId = $this->positiveId($data['customer_id'] ?? null, 'customer_id');
        $warehouseId = $this->positiveId($data['consignment_warehouse_id'] ?? null, 'consignment_warehouse_id');
        $type = strtolower(trim((string) ($data['account_type'] ?? 'consignment')));
        if (!in_array($type, ['consignment', 'wholesale', 'hybrid'], true)) {
            throw new B2BDomainException('B2B account type is invalid.');
        }
        $customer = $this->repository->customer($customerId);
        if ($customer === null || (string) ($customer['status'] ?? '') !== 'active') {
            throw new B2BDomainException('Customer is missing or inactive.');
        }
        $warehouse = $this->repository->warehouse($warehouseId);
        if ($warehouse === null || !(bool) ($warehouse['is_active'] ?? false)) {
            throw new B2BDomainException('Consignment warehouse is missing or inactive.');
        }
        if (in_array($type, ['consignment', 'hybrid'], true) && (string) $warehouse['type'] !== 'consignment') {
            throw new B2BDomainException('Consignment accounts require a consignment warehouse.');
        }

        $payload = [
            'customer_id' => $customerId,
            'code' => $this->requiredCode($data['code'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'account_type' => $type,
            'consignment_warehouse_id' => $warehouseId,
            'credit_limit_irr' => $this->positiveMoney($data['credit_limit_irr'] ?? null, 'credit_limit_irr'),
            'commission_rate_bps' => $this->rateBps($data['commission_rate_bps'] ?? 0),
            'settlement_terms_days' => $this->nonNegativeMoney(
                $data['settlement_terms_days'] ?? 0,
                'settlement_terms_days'
            ),
            'receivable_subsidiary_ledger_id' => $this->optionalPositiveId(
                $data['receivable_subsidiary_ledger_id'] ?? null
            ),
            'floating_detail_id' => $this->optionalPositiveId($data['floating_detail_id'] ?? null),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->upsertAccount($payload);
            $this->audit->record(
                $result['created'] ? 'b2b.account.created' : 'b2b.account.updated',
                'b2b_account',
                (string) $result['id'],
                [
                    'code' => $payload['code'],
                    'account_type' => $payload['account_type'],
                    'credit_limit_irr' => $payload['credit_limit_irr'],
                ]
            );

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function account(int $accountId): array
    {
        $account = $this->repository->account($this->positiveId($accountId, 'account_id'));
        if ($account === null) {
            throw new RuntimeException('B2B account not found.');
        }
        $account['available_credit_irr'] = $this->credit->residual(
            (int) $account['current_receivable_irr'],
            (int) $account['credit_limit_irr']
        );

        return $account;
    }

    /** @return list<array<string, mixed>> */
    public function accounts(array $filters = []): array
    {
        return $this->repository->accounts([
            'account_type' => $this->nullableText($filters['account_type'] ?? null, 20),
            'status' => $this->nullableText($filters['status'] ?? null, 20),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function statement(int $accountId): array
    {
        $this->requireAccount($this->positiveId($accountId, 'account_id'));

        return $this->repository->statement($accountId);
    }
}
