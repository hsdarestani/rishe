# Analytics and Executive Intelligence

Rishe Analytics is an event-driven reporting boundary. Dashboards never aggregate directly from mutable operational tables. Operational audit records are converted into immutable business events, then projected into dedicated dimensions, daily facts, inventory snapshots, targets, and executive alerts.

## Business event store

`rishe_business_events` records enriched, append-only events with:

- canonical event type and timestamp
- actor, branch, sales channel, source, and campaign
- customer, order, product, and product-line dimensions
- scaled quantity and integer-IRR revenue, COGS, gross profit, and discount
- province, city, aggregate reference, correlation id, and original payload

The audit bridge recognizes customer registration, order creation/payment/cancellation/return, shipment/delivery, price changes, stock movements, production, supplier receipts, discounts, coupon usage, and SMS events. Manual integrations may append a canonical event through REST when no operational audit exists.

One audit event may produce multiple product-line events for a multi-line order. `source_audit_event_id + event_sequence` guarantees idempotency.

## Analytical boundary

The default adapter stores the analytical schema in dedicated `rishe_analytics_*` tables in the WordPress database. The application depends on `AnalyticsRepository`, so a separate database or warehouse adapter can replace the default without changing domain/application services.

Projection uses a monotonic cursor and short database transactions. It maintains:

- customer dimension: geography, registration, first/last purchase, source
- product dimension: SKU, product line, category, supplier, latest batch
- order dimension: channel, source, campaign, branch, salesperson, discount, totals
- time dimension: day, week, month, quarter, year
- daily facts by event and business dimensions
- daily inventory/SKU snapshots

Product line, category, supplier, and alert thresholds can be configured with:

- `rishe_product_analytics_dimensions`
- `rishe_analytics_min_stock`
- `rishe_unpaid_alert_minutes`
- `rishe_return_alert_basis_points`

## Targets

Targets support sales, gross profit, and order count over day, week, or month periods. Optional dimensions are product line, sales channel, province, and city.

Responses include integer values for target, actual, variance, and achievement basis points. No floating point is used.

## Attribution

Default sources are seeded for Website, Instagram, Telegram, SMS, Digikala, Basalam, Snapp Shop, Snapp Pay, POS, phone, referral, and direct traffic.

Campaigns store name, source/channel, start/end, objective, target, budget, and lifecycle. Order attribution is immutable after first creation and may include source, campaign, branch, salesperson, province, and city.

## Price history

Every price record carries purchase price, COGS, selling price, channel, effective dates, reason, and actor. Commercial fields are immutable. An open interval can only be closed once when a later price starts.

## Executive alerts

The MVP evaluator creates fingerprinted alerts for:

- sales below COGS
- sales below an active target
- stock below configured minimum
- unusual return rate
- unpaid orders older than the configured threshold

Alerts have Critical, Warning, or Info severity and Open, Acknowledged, or Resolved state. Repeated detections increment one alert instead of creating noise.

## Automation

`rishe_analytics_maintenance` runs every five minutes through Action Scheduler or WP-Cron. It drains event projections, refreshes today's inventory snapshot, and evaluates alert rules.

## Administration UI

The Analytics submenu uses a flat geometric responsive UI and exposes executive, sales, inventory, finance, and customer dashboards, targets, and alerts. It is read-only by default and calls protected REST routes.

## REST endpoints

- `GET|POST /analytics/sources`
- `GET|POST /analytics/campaigns`
- `POST /analytics/orders/{id}/attribution`
- `GET|POST /analytics/prices`
- `GET|POST /analytics/targets`
- `GET|POST /analytics/events`
- `POST /analytics/project`
- `POST /analytics/snapshot`
- `POST /analytics/alerts/evaluate`
- `GET /analytics/alerts`
- `POST /analytics/alerts/{id}/{open|acknowledged|resolved}`
- `GET /analytics/dashboard/{executive|sales|inventory|finance|customers}`

Read routes require `rishe_view_reports`. Mutations require `rishe_manage_analytics`.
