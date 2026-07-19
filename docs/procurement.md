# Procurement and Accounts Payable

The procurement bounded context converts approved supplier commitments into costed inventory batches, supplier liabilities, treasury payments, and balanced accounting entries.

## Supplier master

A supplier has a stable code and may contain Iranian tax identifiers, payment terms, credit limits, IBAN, an accounts-payable subsidiary ledger, and a floating detail. Supplier records are never used as WordPress posts.

## Purchase-order lifecycle

Purchase-order states are:

- `draft`
- `approved`
- `partially_received`
- `received`
- `cancelled`

Draft orders may be corrected. Approval assigns an atomic fiscal-year document number and makes supplier, warehouse, product, quantity, price, discount, tax, and totals immutable. An approved order can be cancelled only before any receipt has created a liability.

## Receipt and landed-cost flow

A receipt executes in one database transaction:

1. Lock the approved purchase order and its current received quantities.
2. Validate that each receipt quantity is within the outstanding quantity.
3. Prorate the original line discount and input tax; the final partial receipt receives any integer-IRR remainder.
4. Allocate freight, insurance, packaging, customs, handling, or other landed costs by merchandise value or scaled quantity.
5. Create a purchase receipt and immutable receipt lines.
6. Create inventory batches whose unit cost includes merchandise after discount plus allocated landed cost.
7. Increase the supplier liability by merchandise, input tax, and actual landed cost.
8. Append the supplier-ledger charge.
9. Post inventory/input-tax/accounts-payable accounting when configured.
10. Move the order to `partially_received` or `received`.

Taxes are kept separate from capitalized inventory cost. Because inventory unit cost is stored as integer IRR, the unit cost uses deterministic half-up rounding while the exact supplier liability remains on the purchase receipt and supplier ledger.

## Supplier payments

A supplier payment requires an imported treasury transaction with direction `debit`. The payment amount cannot exceed either the unmatched treasury balance or the outstanding received liability.

The payment operation:

1. locks the purchase order and treasury transaction;
2. creates a treasury reconciliation match of type `purchase`;
3. posts accounts-payable versus bank/cash accounting when mappings are available;
4. creates an immutable purchase-payment row;
5. appends a supplier-ledger payment entry; and
6. updates the outstanding purchase liability.

Treasury-transaction reuse is idempotent and cannot be redirected to a different purchase or amount.

## Accounting configuration

Store `rishe_procurement_accounting_mapping` as an option containing:

- `fiscal_year`
- `inventory_subsidiary_ledger_id`
- `payable_subsidiary_ledger_id`
- `input_tax_subsidiary_ledger_id` when purchase tax is used
- optional floating-detail ids for inventory and input tax

A supplier-specific payable ledger and floating detail override the default payable mapping. Treasury payment credit lines use the subsidiary and floating-detail mapping on the selected treasury account.

If mappings are incomplete, receipt or payment operations remain successful and record `accounting_status=pending_configuration`; their inventory, treasury, and supplier-ledger effects are not rolled back merely because accounting configuration is absent.

## REST endpoints

All routes use the `rishe/v1` namespace.

- `POST /procurement/suppliers`
- `GET /procurement/suppliers`
- `GET /procurement/suppliers/{id}`
- `GET /procurement/suppliers/{id}/statement`
- `POST /procurement/purchase-orders`
- `GET /procurement/purchase-orders`
- `GET /procurement/purchase-orders/{id}`
- `POST /procurement/purchase-orders/{id}/approve`
- `POST /procurement/purchase-orders/{id}/cancel`
- `POST /procurement/purchase-orders/{id}/receipts`
- `POST /procurement/purchase-orders/{id}/payments`
- `GET /procurement/receipts`
- `GET /procurement/receipts/{id}`

Mutation endpoints require `rishe_manage_procurement`; reports require `rishe_view_reports`.

## Database protection

Database triggers enforce valid purchase-order lifecycle transitions, immutable approved commercial fields, monotonic received and paid balances, immutable posted receipts, immutable landed costs, append-only supplier ledger, and immutable supplier payments. Corrections must use explicit reversal or return workflows rather than update or delete operations.
