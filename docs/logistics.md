# Logistics Integrations

The logistics bounded context manages carrier configuration, shipment snapshots, packages, rates, bookings, labels, tracking, delivery exceptions, actual carrier costs, and treasury reconciliation.

## Carrier adapters

Supported carrier codes are `post`, `tipax`, `snapp`, `alopeyk`, and `custom`. Rishe does not hard-code undocumented or merchant-specific carrier APIs. Every carrier stores a configurable HTTP/JSON contract containing:

- operation endpoints and HTTP methods;
- request field maps from the canonical Rishe shipment payload;
- response field maps for rates and bookings;
- tracking-event and status maps;
- static and credential-backed request headers;
- amount multiplier for converting provider units such as toman into IRR; and
- timeout and webhook event-path settings.

API credentials and webhook secrets are encrypted with AES-256-GCM using WordPress installation salts. They are never returned by the REST API or written to audit payloads.

## Shipment lifecycle

Shipment states are:

- `draft`
- `quoted`
- `booked`
- `label_ready`
- `in_transit`
- `delivered`
- `exception`
- `cancelled`
- `returned`

A shipment stores immutable sender, recipient, declared value, customer shipping charge, COD amount, and package snapshots. Packages store physical and volumetric weights in integer grams and dimensions in millimetres.

A shipment may be linked to a Rishe sales order. The sales-order shipping charge and total are used as defaults for charged shipping and declared value, but the logistics record remains an independent audited aggregate.

## Quote and booking

Rate requests are sent through the configured carrier adapter and stored as immutable quote rows. Selecting a quote records carrier, service, and quoted cost.

Booking uses the selected or explicitly supplied carrier and service. Carrier shipment id, tracking number, label URL, and booking timestamp become immutable after they are assigned. Repeating a completed booking returns the existing shipment rather than creating a second carrier shipment.

## Tracking and webhooks

Tracking can be refreshed on demand or received through a public signed webhook. Webhook signatures use HMAC-SHA256 and accept hexadecimal or Base64 encoding.

Provider statuses must be explicitly mapped to Rishe statuses. Events are append-only and idempotent by carrier event id and event hash. The state machine allows delivery exceptions to recover to in-transit or delivered while preventing terminal shipments from moving backwards.

## Carrier costs and variance

Actual carrier costs are imported as immutable rows with types:

- `freight`
- `surcharge`
- `insurance`
- `cod`
- `return`
- `adjustment`

The shipment continuously reports:

```text
cost variance = actual carrier cost - shipping charged to customer
```

A positive variance means logistics cost exceeded the customer charge. A negative variance means the shipping charge exceeded carrier cost.

## Treasury settlement

Carrier settlement requires an imported treasury transaction with direction `debit`. The amount cannot exceed either unmatched treasury value or recorded unsettled carrier cost.

The transaction is matched using `match_type=logistics_cost`. An immutable settlement row is created, settled cost increases, and shipping-expense-versus-bank accounting is posted when mappings are available.

Store `rishe_logistics_accounting_mapping` with:

- `fiscal_year`
- `shipping_expense_subsidiary_ledger_id`
- optional `shipping_expense_floating_detail_id`

A carrier-specific shipping-expense subsidiary ledger overrides the default mapping. If accounting mappings are incomplete, operational settlement succeeds with `accounting_status=pending_configuration`.

## REST endpoints

All routes use the `rishe/v1` namespace.

- `POST /logistics/carriers`
- `GET /logistics/carriers`
- `GET /logistics/carriers/{id}`
- `POST /logistics/shipments`
- `GET /logistics/shipments`
- `GET /logistics/shipments/{id}`
- `POST /logistics/shipments/{id}/quote`
- `POST /logistics/shipments/{id}/book`
- `POST /logistics/shipments/{id}/cancel`
- `POST /logistics/shipments/{id}/tracking/refresh`
- `POST /logistics/shipments/{id}/costs`
- `POST /logistics/shipments/{id}/settlements`
- `POST /integrations/logistics/{carrier}/webhook`

Mutation routes require `rishe_manage_logistics`; reports require `rishe_view_reports`. The public webhook route performs its own carrier lookup and signature verification.

## Database protection

Database triggers enforce immutable shipment snapshots, immutable carrier references after booking, valid lifecycle transitions, monotonic actual and settled costs, exact cost variance, immutable packages and quotes, append-only tracking events, immutable carrier costs, and immutable settlements.
