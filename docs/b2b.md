# Consignment and B2B Settlement

The B2B bounded context manages partner credit, consignment inventory, agent-reported sales, commissions, receivables, and treasury settlement without transferring ownership when goods are merely dispatched.

## B2B accounts

Each account links to one unified CRM customer and has:

- a stable account code and name;
- account type `consignment`, `wholesale`, or `hybrid`;
- a dedicated consignment warehouse;
- an integer-IRR credit limit and current receivable;
- a default commission rate in basis points;
- settlement terms in days; and
- optional receivable subsidiary-ledger and floating-detail mappings.

The account row is locked before a sale report or settlement. A report cannot make current receivables exceed the configured credit limit.

## Consignment dispatches

A dispatch transfers batches from an owned source warehouse to the partner's consignment warehouse. This is an inventory transfer, not a sale, so it creates no revenue or receivable.

Dispatches are idempotent and contain immutable product and quantity snapshots. Each line stores:

- dispatched quantity;
- sold quantity;
- returned quantity; and
- the inventory transfer group UUID.

The invariant is:

```text
sold quantity + returned quantity <= dispatched quantity
```

## Returns

Only unsold and previously unreturned quantity may be returned. A return transfers stock from the consignment warehouse back to an active owned warehouse. Sold goods cannot be returned through the consignment-return flow.

When every dispatch line is fully sold or returned, the dispatch closes automatically.

## Agent sales reports

A posted report:

1. locks the B2B account and checks available credit;
2. validates product, quantity, price, and commission rate;
3. reserves and commits stock from the consignment warehouse;
4. calculates batch-level COGS;
5. allocates sold quantities to the oldest open dispatch lines;
6. calculates gross sales, commission, and net receivable;
7. appends a receivable charge with a due date; and
8. posts revenue, commission, COGS, inventory, and receivable accounting when configured.

Commission uses integer basis points with deterministic half-up rounding:

```text
commission = round(gross x basis_points / 10000)
receivable = gross - commission
```

## Settlement

Settlement requires an imported treasury transaction with direction `credit`. The amount cannot exceed either the unmatched treasury amount or the account's current receivable.

The transaction is matched using `match_type=b2b_account`, the receivable is reduced, an immutable settlement and ledger payment are created, and bank-versus-receivable accounting is posted when mappings are configured.

Treasury-transaction reuse is idempotent and cannot be redirected to a different account or amount.

## Accounting configuration

Store `rishe_b2b_accounting_mapping` as an option containing:

- `fiscal_year`
- `receivable_subsidiary_ledger_id`
- `sales_subsidiary_ledger_id`
- `commission_expense_subsidiary_ledger_id`
- `cogs_subsidiary_ledger_id`
- `inventory_subsidiary_ledger_id`
- optional floating-detail ids for sales, commission expense, COGS, and inventory

An account-specific receivable subsidiary ledger and floating detail override the defaults. Settlement bank lines use the mapping on the matched treasury account.

If mappings are incomplete, operational posting succeeds and records `accounting_status=pending_configuration`.

## REST endpoints

All routes use the `rishe/v1` namespace.

- `POST /b2b/accounts`
- `GET /b2b/accounts`
- `GET /b2b/accounts/{id}`
- `GET /b2b/accounts/{id}/statement`
- `POST /b2b/accounts/{id}/settlements`
- `POST /consignment/dispatches`
- `GET /consignment/dispatches`
- `GET /consignment/dispatches/{id}`
- `POST /consignment/dispatches/{id}/returns`
- `POST /consignment/sales-reports`
- `GET /consignment/sales-reports`
- `GET /consignment/sales-reports/{id}`

Mutation endpoints require `rishe_manage_b2b`; reports require `rishe_view_reports`.

## Database protection

Database triggers enforce credit limits, valid dispatch and report lifecycle transitions, monotonic sold and returned quantities, immutable transferred lines, immutable posted returns and reports, immutable sale allocations, append-only B2B ledger entries, and immutable settlements.
