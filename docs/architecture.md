# Rishe Architecture

Rishe runs inside WordPress but treats WordPress as the runtime, identity provider, administration shell, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code must not call WordPress hooks directly.
- WordPress and WooCommerce integration belongs under `Infrastructure`.
- Financial, inventory, and production ledgers are append-only after posting or completion.
- Cross-module side effects use audited application services and, where asynchronous delivery is needed, the outbox table.
- Every use case that mutates more than one ERP record must execute through the transaction manager.

## Foundation tables

- `rishe_migrations`: applied schema migrations.
- `rishe_audit_log`: immutable operational and security audit events.
- `rishe_idempotency_keys`: duplicate and replay protection for external commands and webhooks.
- `rishe_outbox`: reliable publication of integration events.

## Accounting tables

- `rishe_account_groups`
- `rishe_general_ledgers`
- `rishe_subsidiary_ledgers`
- `rishe_floating_details`
- `rishe_voucher_sequences`
- `rishe_journal_vouchers`
- `rishe_journal_entries`

Voucher posting locks the voucher row, recalculates totals from journal entries, validates required floating details, allocates a fiscal-year sequence number, updates status, and writes an audit event in one transaction. Reversal never edits accounting amounts on the original voucher; it posts a new opposite voucher and marks the original as reversed.

## Inventory tables

- `rishe_warehouses`
- `rishe_products`
- `rishe_inventory_batches`
- `rishe_stock_reservations`
- `rishe_stock_reservation_allocations`
- `rishe_stock_movements`

Stock reservations lock eligible batch rows and persist exact batch allocations. Commit deducts on-hand and reserved quantities together; release only reduces reserved quantity. Transfers preserve source batch cost and traceability through `origin_batch_id` and paired movements sharing a transfer UUID.

Database triggers reject invalid batch balances and all direct updates or deletes against stock movements and reservation allocations.

## Manufacturing tables

- `rishe_boms`
- `rishe_bom_components`
- `rishe_production_orders`
- `rishe_production_consumptions`
- `rishe_production_outputs`

BOM activation retires an older active version with the same code. Production locks the active formula and eligible source batches, computes proportional requirements and waste, writes immutable component consumptions, creates a fully costed finished batch, writes production stock movements, and completes the order in one transaction.

All table names are prefixed with the active WordPress database prefix. ERP tables are retained during normal plugin uninstall to prevent accidental loss of financial, inventory, or production records.

## Next bounded context

Omnichannel sales and CRM will introduce unified customers, channel orders, WooCommerce ingestion, inventory reservations, payments, promotions, and automatic accounting integration.
