# Rishe Architecture

Rishe runs inside WordPress but treats WordPress as the runtime, identity provider, administration shell, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code must not call WordPress hooks directly.
- WordPress and WooCommerce integration belongs under `Infrastructure`.
- Financial, inventory, production, sales-payment, treasury, and supplier ledgers are append-only after posting or completion.
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

## Sales and CRM tables

- `rishe_customers`
- `rishe_customer_channels`
- `rishe_channel_prices`
- `rishe_promotions`
- `rishe_sales_orders`
- `rishe_sales_order_lines`
- `rishe_sales_payments`
- `rishe_promotion_redemptions`
- `rishe_loyalty_ledger`
- `rishe_order_status_history`

Customer identity is normalized around a unique Iranian mobile number. Channel orders are idempotent by external order id or explicit idempotency key. Order creation reserves every stock line in the same transaction; payment commits reservations, calculates batch-level COGS, posts accounting when configured, and records immutable payment and loyalty entries.

## Treasury tables

- `rishe_treasury_accounts`
- `rishe_treasury_providers`
- `rishe_payment_links`
- `rishe_treasury_transactions`
- `rishe_reconciliation_matches`
- `rishe_treasury_settlements`

Payment links are created locally before calling a provider and use the same idempotency reference at the provider boundary. Signed callbacks import an immutable credit transaction, capture a linked sales order, create the reconciliation match, and move the link to its terminal paid state. Bank and gateway transactions, matches, and settlements cannot be edited or deleted.

## Procurement tables

- `rishe_purchase_sequences`
- `rishe_suppliers`
- `rishe_purchase_orders`
- `rishe_purchase_order_lines`
- `rishe_purchase_receipts`
- `rishe_purchase_receipt_lines`
- `rishe_purchase_landed_costs`
- `rishe_supplier_ledger`
- `rishe_purchase_payments`

Purchase orders are commercially mutable only while draft. Approval allocates an atomic fiscal-year number. Partial receipts prorate line discounts and input tax, allocate actual landed costs by merchandise value or quantity, create fully costed inventory batches, append supplier liability entries, and post accounting when configured. Supplier payments require debit treasury transactions and append both reconciliation and supplier-ledger entries.

All table names are prefixed with the active WordPress database prefix. ERP tables are retained during normal plugin uninstall to prevent accidental loss of financial, inventory, production, sales, CRM, treasury, or procurement records.

## Next bounded context

Consignment and B2B settlement will introduce consignment dispatches, returns, agent sales reports, commissions, credit limits, and settlement workflows.
