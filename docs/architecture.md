# Rishe Architecture

Rishe runs inside WordPress while treating WordPress as runtime, identity provider, administration shell, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code do not call WordPress hooks directly.
- WordPress, WooCommerce, carrier, bank, and tax integrations belong under `Infrastructure`.
- Posted financial, inventory, production, sales, treasury, supplier, B2B, and logistics ledgers are append-only.
- Multi-record mutations execute through the transaction manager.
- External commands and webhooks use idempotency, signatures, immutable snapshots, and audit logs.

## Implemented bounded contexts

### Foundation

`rishe_migrations`, `rishe_audit_log`, `rishe_idempotency_keys`, and `rishe_outbox` provide schema versioning, auditability, replay protection, and reliable integration publication.

### Accounting

Four-level chart tables, voucher sequences, vouchers, and journal entries support balanced posting and reversal without mutating posted lines.

### Inventory

Warehouse, product, batch, reservation, allocation, and stock-movement tables support scaled quantities, FIFO/LIFO, locking, transfers, COGS, and immutable movement ledgers.

### Manufacturing

BOM, component, production-order, consumption, and output tables freeze formulas and trace input batches into fully costed finished batches.

### Sales and CRM

Customer, channel, price, promotion, order, payment, loyalty, and history tables manage unified customers and idempotent omnichannel sales.

### Treasury

Account, provider, payment-link, transaction, match, and settlement tables manage encrypted integrations and immutable bank reconciliation.

### Procurement

Supplier, purchase-order, receipt, landed-cost, payable-ledger, and payment tables convert supplier commitments into costed stock and liabilities.

### B2B and consignment

Partner account, dispatch, return, report, allocation, receivable-ledger, and settlement tables retain ownership during consignment and enforce credit limits.

### Logistics

- `rishe_logistics_carriers`
- `rishe_shipments`
- `rishe_shipment_packages`
- `rishe_shipment_quotes`
- `rishe_shipment_tracking_events`
- `rishe_shipment_costs`
- `rishe_logistics_settlements`

Shipment creation freezes address, value, customer charge, COD, and package metrics. Carrier adapters translate a canonical payload into provider-specific HTTP/JSON contracts. Quotes, events, costs, and settlements are immutable. Signed webhooks advance a guarded shipment lifecycle, and debit treasury transactions settle only recorded carrier costs.

All tables use the active WordPress database prefix. ERP records are retained during normal uninstall to prevent accidental loss of operational or financial history.

## Next bounded context

Iranian fiscal invoicing and tax compliance will add official invoice snapshots, taxpayer-system identifiers, payload generation, cryptographic signing, submission attempts, retries, correction invoices, and cancellation workflows.
