# Treasury and Payment Links

The treasury bounded context manages bank, cash, POS, and gateway accounts; payment links; immutable bank and gateway transactions; settlement records; and reconciliation against ERP entities.

## Treasury accounts

Each treasury account has a stable code, type, IRR currency, optional IBAN/card/account identifiers, and optional accounting subsidiary/floating-detail mapping.

Supported account types:

- `bank`
- `cash`
- `pos`
- `gateway`

## Payment providers

Providers are configured with a non-secret JSON configuration and encrypted secrets. The supported adapters are:

- `configurable_hmac`
- `blue_business`

The Blue Business adapter is configuration-driven because production endpoint URLs, authentication headers, and response field names are supplied through the merchant's private provider documentation and credentials. No undocumented production endpoint is hard-coded.

Provider configuration supports:

- `create_url`
- `authorization_header` and `authorization_scheme`
- static request `headers`
- canonical-to-provider `request_fields`
- response field paths such as `response_link_id_path` and `response_url_path`
- callback field paths and status mapping
- callback signature header and `hex` or `base64` HMAC-SHA256 encoding
- `amount_multiplier` when a provider reports toman instead of rial

Secrets such as `api_token` and `webhook_secret` are encrypted with AES-256-GCM using WordPress authentication salts before storage.

## Payment-link lifecycle

1. Validate the provider and linked sales order.
2. Lock the immutable order amount.
3. Create a local `creating` payment-link record using an idempotency key.
4. Call the configured provider with the local external reference and callback URL.
5. Store the provider link id and payment URL and mark the link `active`.
6. Verify callback HMAC and canonical fields.
7. Import the provider credit as an immutable treasury transaction.
8. Capture the linked sales order payment, commit inventory, and post sales/COGS accounting.
9. Create an immutable reconciliation match and mark the payment link `paid`.

Payment-link states are `creating`, `active`, `paid`, `failed`, `expired`, and `cancelled`. Paid, expired, and cancelled links are terminal.

## Reconciliation

Treasury transactions are unique by treasury account and external transaction id. A transaction may be matched to one or more ERP entities up to its full amount.

Supported match types:

- `sales_order`
- `settlement`
- `purchase`
- `expense`
- `manual`

A sales-order match must be a credit and must equal the complete immutable sales-order total. Matching captures the sales payment idempotently.

## Settlements

Gateway and POS settlements store gross, fee, and net amounts, with the invariant:

```text
gross amount - fee amount = net amount
```

Settlement records are immutable. Detailed settlement-to-payment allocation can be extended after real provider statement samples are available.

## REST endpoints

All routes use the `rishe/v1` namespace.

- `POST /treasury/accounts`
- `GET /treasury/accounts`
- `POST /treasury/providers`
- `GET /treasury/providers`
- `POST /treasury/payment-links`
- `GET /treasury/payment-links`
- `POST /treasury/transactions/import`
- `GET /treasury/transactions`
- `POST /treasury/transactions/{id}/matches`
- `POST /treasury/settlements`
- `GET /treasury/settlements`
- `POST /integrations/treasury/{provider}/callback`

Mutation endpoints require `rishe_manage_treasury`; reports require `rishe_view_reports`. Provider callbacks are public but require the configured HMAC signature.

## Database protection

Database triggers enforce positive IRR amounts, valid directions and status transitions, immutable payment-link commercial fields, and immutable treasury transactions, reconciliation matches, and settlements.
