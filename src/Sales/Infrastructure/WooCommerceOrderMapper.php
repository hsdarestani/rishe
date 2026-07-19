<?php

declare(strict_types=1);

namespace Rishe\Sales\Infrastructure;

use Rishe\Inventory\Domain\Quantity;
use Rishe\Sales\Application\SalesRepository;
use Rishe\Sales\Domain\Exception\SalesDomainException;

final class WooCommerceOrderMapper
{
    public function __construct(private readonly SalesRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{order: array<string, mixed>, payment: array<string, mixed>|null, cancelled: bool, completed: bool}
     */
    public function map(array $payload): array
    {
        $externalId = trim((string) ($payload['id'] ?? ''));
        if ($externalId === '') {
            throw new SalesDomainException('WooCommerce order id is required.');
        }
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $items = $payload['line_items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new SalesDomainException('WooCommerce order contains no line items.');
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'IRR')));
        $multiplier = match ($currency) {
            'IRR' => 1,
            'IRT', 'TMN', 'TOMAN' => 10,
            default => throw new SalesDomainException('WooCommerce order currency must be IRR or IRT.'),
        };
        $lines = [];
        foreach (array_values($items) as $item) {
            if (!is_array($item)) {
                throw new SalesDomainException('WooCommerce line item is invalid.');
            }
            $wcProductId = (int) ($item['variation_id'] ?? 0);
            if ($wcProductId < 1) {
                $wcProductId = (int) ($item['product_id'] ?? 0);
            }
            $product = $this->repository->productByWooCommerceId($wcProductId);
            if ($product === null && (int) ($item['product_id'] ?? 0) !== $wcProductId) {
                $product = $this->repository->productByWooCommerceId((int) $item['product_id']);
            }
            if ($product === null || !(bool) ($product['is_active'] ?? false)) {
                throw new SalesDomainException('WooCommerce line item is not mapped to an active Rishe product.');
            }

            $quantity = Quantity::fromInput($this->decimalString($item['quantity'] ?? null, 'quantity'));
            $subtotal = $this->money($item['subtotal'] ?? null, $multiplier, 'line subtotal');
            $net = $this->money($item['total'] ?? null, $multiplier, 'line total');
            if ($net > $subtotal) {
                throw new SalesDomainException('WooCommerce line total cannot exceed its subtotal.');
            }
            $unitPrice = intdiv(($subtotal * Quantity::SCALE) + $quantity->scaled() - 1, $quantity->scaled());
            $calculatedGross = intdiv($quantity->scaled() * $unitPrice, Quantity::SCALE);

            $lines[] = [
                'product_id' => (int) $product['id'],
                'quantity' => $quantity->decimal(),
                'unit_price_irr' => $unitPrice,
                'line_discount_irr' => $calculatedGross - $net,
            ];
        }

        $warehouseId = (int) get_option('rishe_woocommerce_warehouse_id', 0);
        if ($warehouseId < 1) {
            throw new SalesDomainException('WooCommerce warehouse mapping is not configured.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'pending')));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        $isPaid = ($payload['date_paid'] ?? null) !== null
            || in_array($status, ['processing', 'completed'], true);
        $payment = null;
        if ($isPaid) {
            $payment = [
                'provider' => 'woocommerce',
                'external_payment_id' => $transactionId !== '' ? $transactionId : 'wc-order-' . $externalId,
                'amount_irr' => $this->money($payload['total'] ?? null, $multiplier, 'order total'),
                'raw_hash' => null,
            ];
        }

        return [
            'order' => [
                'channel' => 'woocommerce',
                'external_order_id' => $externalId,
                'warehouse_id' => $warehouseId,
                'customer' => [
                    'mobile' => $billing['phone'] ?? null,
                    'first_name' => $billing['first_name'] ?? null,
                    'last_name' => $billing['last_name'] ?? null,
                    'email' => $billing['email'] ?? null,
                    'external_customer_id' => isset($payload['customer_id'])
                        ? (string) $payload['customer_id']
                        : null,
                    'metadata' => ['billing_company' => $billing['company'] ?? null],
                ],
                'lines' => $lines,
                'shipping_irr' => $this->money($payload['shipping_total'] ?? 0, $multiplier, 'shipping total'),
                'tax_irr' => $this->money($payload['total_tax'] ?? 0, $multiplier, 'tax total'),
                'correlation_id' => 'woocommerce-order-' . $externalId,
            ],
            'payment' => $payment,
            'cancelled' => in_array($status, ['cancelled', 'failed'], true),
            'completed' => $status === 'completed',
        ];
    }

    private function decimalString(mixed $value, string $field): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        $text = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $text)) {
            throw new SalesDomainException('WooCommerce ' . $field . ' is invalid.');
        }

        return $text;
    }

    private function money(mixed $value, int $multiplier, string $field): int
    {
        if (is_int($value)) {
            return $value * $multiplier;
        }
        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        $text = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw new SalesDomainException('WooCommerce ' . $field . ' is invalid.');
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        if ((int) str_pad($fraction, 2, '0') !== 0) {
            throw new SalesDomainException('WooCommerce monetary values must resolve to whole rial or toman amounts.');
        }

        return (int) $whole * $multiplier;
    }
}
