# Omnichannel Sales and CRM

The sales bounded context keeps customer identity, pricing, promotions, reservations, payments, loyalty, COGS, and accounting consistent across WooCommerce, website, Telegram, Instagram, Bale, POS, B2B, events, and manual orders.

## Customer identity

Iranian mobile numbers are normalized to E.164 form such as `+989121234567` and are unique across the ERP. Channel-specific identifiers are attached to the same customer through `rishe_customer_channels`.

## Order lifecycle

1. Resolve or create the customer by normalized mobile.
2. Resolve explicit or channel-specific prices.
3. Apply line discounts, one promotion, and optional loyalty redemption.
4. Create the order and immutable commercial snapshots.
5. Reserve every order line against exact inventory batches.
6. Capture payment idempotently.
7. Commit inventory reservations and calculate COGS per line.
8. Post sales and COGS accounting when ledger mapping is configured.
9. Earn loyalty points and append status/audit entries.

Unpaid orders may be cancelled, which releases reservations and restores redeemed loyalty points. Paid orders may be completed after fulfillment.

## Accounting configuration

Store `rishe_sales_accounting_mapping` as an option containing:

- `fiscal_year`
- `settlement_subsidiary_ledger_id`
- `sales_subsidiary_ledger_id`
- `cogs_subsidiary_ledger_id`
- `inventory_subsidiary_ledger_id`
- optional floating-detail ids for each line

When mapping is absent, payment remains successful and the order receives `accounting_status=pending_configuration`. Use the retry endpoint after configuration.

## WooCommerce integration

Configure:

- `rishe_woocommerce_webhook_secret`
- `rishe_woocommerce_warehouse_id`
- `rishe_system_user_id`

WooCommerce webhook signatures use base64 HMAC-SHA256 in `X-WC-Webhook-Signature`. Product and variation ids must map to active Rishe products through `wc_product_id`.

## Payment callbacks

Store provider secrets in the `rishe_payment_webhook_secrets` option as `provider => secret`. Send a lowercase hexadecimal HMAC-SHA256 signature in `X-Rishe-Signature`.

## REST endpoints

- `POST /crm/customers`
- `GET /crm/customers/{id}`
- `POST /sales/channel-prices`
- `POST /sales/promotions`
- `POST /sales/orders`
- `GET /sales/orders`
- `GET /sales/orders/{id}`
- `POST /sales/orders/{id}/payments`
- `POST /sales/orders/{id}/cancel`
- `POST /sales/orders/{id}/complete`
- `POST /sales/orders/{id}/accounting/retry`
- `POST /integrations/woocommerce/orders`
- `POST /integrations/payments/{provider}/callback`

## Monetary and quantity conventions

All money is stored as non-negative integer Iranian rial. Quantities use the inventory scale of four decimal places. Captured payment must equal the immutable order total.

## Database protection

Database triggers reject invalid totals, negative loyalty balances, invalid status transitions, commercial order mutations, invalid lines, and payment mutation. Payments, loyalty entries, promotion redemptions, and status history are append-only.
