<?php

declare(strict_types=1);

namespace Rishe\Treasury\Application;

use Rishe\Treasury\Domain\Exception\TreasuryDomainException;
use Rishe\Treasury\Domain\PaymentLinkStatus;
use RuntimeException;
use Throwable;

trait TreasuryPaymentOperations
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createPaymentLink(array $data, int $actorUserId): array
    {
        $providerCode = strtolower($this->code($data['provider'] ?? null));
        $provider = $this->repository->providerByCode($providerCode);
        if ($provider === null || !(bool) ($provider['is_active'] ?? false)) {
            throw new TreasuryDomainException('Payment provider is missing or inactive.');
        }
        $actor = $this->actor($actorUserId);
        $salesOrderId = $this->optionalPositiveId($data['sales_order_id'] ?? null);
        $amount = $this->optionalMoney($data['amount_irr'] ?? null);
        $customerId = $this->optionalPositiveId($data['customer_id'] ?? null);
        if ($salesOrderId !== null) {
            $order = $this->sales->order($salesOrderId);
            if ($order === null) {
                throw new RuntimeException('Sales order not found.');
            }
            if ((string) ($order['status'] ?? '') !== 'pending_payment') {
                throw new TreasuryDomainException('A payment link can be created only for a pending-payment order.');
            }
            $orderAmount = (int) $order['total_irr'];
            if ($amount !== null && $amount !== $orderAmount) {
                throw new TreasuryDomainException('Payment link amount must equal the sales order total.');
            }
            $amount = $orderAmount;
            $customerId = (int) $order['customer_id'];
        }
        if ($amount === null || $amount < 1) {
            throw new TreasuryDomainException('Payment link amount must be greater than zero.');
        }
        $idempotencyKey = $this->requiredReference($data['idempotency_key'] ?? null, 'idempotency_key', 100);
        $expiresAt = $this->nullableDateTime($data['expires_at'] ?? null);
        $callbackUrl = $this->requiredUrl($data['callback_url'] ?? null, 'callback_url');
        $referenceType = $this->nullableText(
            $data['reference_type'] ?? ($salesOrderId === null ? null : 'sales_order'),
            50
        );
        $referenceId = $this->nullableText(
            $data['reference_id'] ?? ($salesOrderId === null ? null : (string) $salesOrderId),
            191
        );
        $payloadHash = hash('sha256', (string) json_encode([
            'provider' => $providerCode,
            'amount_irr' => $amount,
            'sales_order_id' => $salesOrderId,
            'customer_id' => $customerId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'expires_at' => $expiresAt,
            'callback_url' => $callbackUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payload = [
            'provider_id' => (int) $provider['id'],
            'provider_code' => $providerCode,
            'treasury_account_id' => (int) $provider['treasury_account_id'],
            'sales_order_id' => $salesOrderId,
            'customer_id' => $customerId,
            'amount_irr' => $amount,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $this->nullableText($data['description'] ?? null, 500),
            'expires_at' => $expiresAt,
            'callback_url' => $callbackUrl,
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'actor_user_id' => $actor,
        ];

        $created = $this->transactions->run(fn (): array => $this->repository->createPaymentLink($payload));
        if ($created['idempotent']) {
            return $this->requirePaymentLink((int) $created['id']);
        }

        try {
            $providerResult = $this->gateway->create($provider, $payload + [
                'payment_link_id' => (int) $created['id'],
            ]);
            $this->transactions->run(function () use ($created, $providerResult, $payload): void {
                $this->repository->activatePaymentLink((int) $created['id'], $providerResult);
                $this->audit->record(
                    'treasury.payment_link.activated',
                    'payment_link',
                    (string) $created['id'],
                    ['provider_link_id' => $providerResult['provider_link_id'], 'amount_irr' => $payload['amount_irr']],
                    $payload['correlation_id']
                );
            });
        } catch (Throwable $exception) {
            $this->transactions->run(function () use ($created, $payload): void {
                $this->repository->transitionPaymentLink((int) $created['id'], PaymentLinkStatus::FAILED->value);
                $this->audit->record(
                    'treasury.payment_link.failed',
                    'payment_link',
                    (string) $created['id'],
                    [],
                    $payload['correlation_id']
                );
            });
            throw $exception;
        }

        return $this->requirePaymentLink((int) $created['id']);
    }

    /** @param array<string, string> $headers @return array<string, mixed> */
    public function handleCallback(
        string $providerCode,
        string $body,
        array $headers,
        int $actorUserId
    ): array {
        $provider = $this->repository->providerByCode(strtolower($this->code($providerCode)));
        if ($provider === null || !(bool) ($provider['is_active'] ?? false)) {
            throw new TreasuryDomainException('Payment provider is missing or inactive.');
        }
        $callback = $this->gateway->parseCallback($provider, $body, $headers);
        $status = strtolower($callback['status']);
        if (!in_array($status, ['paid', 'failed', 'expired', 'cancelled'], true)) {
            throw new TreasuryDomainException('Payment provider callback status is invalid.');
        }
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($provider, $callback, $status, $actor): array {
            $link = $this->repository->paymentLinkByProviderReference(
                (string) $provider['code'],
                $callback['provider_link_id']
            );
            if ($link === null) {
                throw new RuntimeException('Payment link not found.');
            }
            $current = PaymentLinkStatus::from((string) $link['status']);
            $next = PaymentLinkStatus::from($status);
            if ($current === PaymentLinkStatus::PAID && $next === PaymentLinkStatus::PAID) {
                return $this->requirePaymentLink((int) $link['id']) + ['idempotent' => true];
            }
            $current->assertTransition($next);
            if ($next !== PaymentLinkStatus::PAID) {
                $this->repository->transitionPaymentLink((int) $link['id'], $next->value);
                $this->audit->record(
                    'treasury.payment_link.' . $next->value,
                    'payment_link',
                    (string) $link['id'],
                    [],
                    $link['correlation_id'] ?? null
                );

                return $this->requirePaymentLink((int) $link['id']) + ['idempotent' => false];
            }
            if ((int) $callback['amount_irr'] !== (int) $link['amount_irr']) {
                throw new TreasuryDomainException('Payment callback amount does not equal the payment link amount.');
            }

            $transaction = $this->repository->importTransaction([
                'treasury_account_id' => (int) $link['treasury_account_id'],
                'direction' => 'credit',
                'amount_irr' => (int) $callback['amount_irr'],
                'transaction_at' => $callback['paid_at'] ?? gmdate('Y-m-d H:i:s'),
                'value_date' => null,
                'external_transaction_id' => $callback['external_transaction_id'],
                'reference' => $callback['provider_link_id'],
                'counterparty_name' => null,
                'counterparty_iban' => null,
                'description' => 'Payment link receipt',
                'source' => (string) $provider['code'],
                'raw_hash' => $callback['raw_hash'],
                'correlation_id' => $link['correlation_id'] ?? null,
                'actor_user_id' => $actor,
            ]);

            $salesResult = null;
            if ((int) ($link['sales_order_id'] ?? 0) > 0) {
                $salesResult = $this->sales->capture((int) $link['sales_order_id'], [
                    'provider' => (string) $provider['code'],
                    'external_payment_id' => $callback['external_transaction_id'],
                    'amount_irr' => (int) $callback['amount_irr'],
                    'raw_hash' => $callback['raw_hash'],
                ], $actor);
                if (!$transaction['idempotent']) {
                    $this->repository->createMatch([
                        'treasury_transaction_id' => (int) $transaction['id'],
                        'match_type' => 'sales_order',
                        'entity_id' => (int) $link['sales_order_id'],
                        'amount_irr' => (int) $callback['amount_irr'],
                        'actor_user_id' => $actor,
                    ]);
                }
            }

            $this->repository->transitionPaymentLink(
                (int) $link['id'],
                PaymentLinkStatus::PAID->value,
                (int) $transaction['id']
            );
            $this->audit->record(
                'treasury.payment_link.paid',
                'payment_link',
                (string) $link['id'],
                ['transaction_id' => $transaction['id'], 'amount_irr' => $callback['amount_irr']],
                $link['correlation_id'] ?? null
            );

            return $this->requirePaymentLink((int) $link['id']) + [
                'idempotent' => $transaction['idempotent'],
                'sales_order' => $salesResult,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function requirePaymentLink(int $paymentLinkId): array
    {
        $link = $this->repository->paymentLink($paymentLinkId);
        if ($link === null) {
            throw new RuntimeException('Payment link not found.');
        }

        return $link;
    }
}
