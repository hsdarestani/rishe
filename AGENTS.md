# Codex Instructions for Rishe

## Product boundary
Rishe is a modular ERP plugin for WordPress and WooCommerce. WordPress is the runtime and admin shell; ERP data must use dedicated database tables rather than posts or post meta.

## Architecture
- PHP 8.1+, strict types, PSR-4 under `Rishe\\`.
- Organize code by bounded context: Accounting, Inventory, Sales, CRM, Treasury, Manufacturing, Logistics, Compliance, Shared, Infrastructure.
- Keep domain rules independent from WordPress hooks and WooCommerce APIs.
- Put WordPress/WooCommerce adapters under `Infrastructure`.
- Use application services for use-cases and database transactions.
- Monetary values are stored as integers in IRR unless a value object explicitly carries another currency.
- Quantities use decimal-safe database columns; never use binary floating point for money or stock.

## Non-negotiable invariants
- Posted journal vouchers must balance: total debit equals total credit.
- Posted financial records and stock ledger records are immutable; corrections use reversal entries.
- Every external webhook must be authenticated, idempotent, replay-resistant, and auditable.
- Stock is reserved before payment and committed only after the relevant business event.
- FIFO consumption must lock affected batch rows inside one database transaction.
- Customer mobile number is a unique business identifier, not the physical database primary key.
- Every table includes created/updated timestamps; ledgers also include actor and correlation identifiers.

## Delivery workflow
- One bounded feature per branch and pull request.
- Add migrations/schema changes, tests, and documentation in the same PR.
- Do not introduce a framework or dependency without documenting the reason.
- Do not implement modules from mock assumptions when an interface can be defined first.
- Before finishing, run syntax checks, coding standards, and tests.

## Initial delivery order
1. Foundation and database migration framework.
2. Accounting chart and balanced journal posting.
3. Warehouses, stock ledger, batches, reservation and FIFO.
4. Products, units, BOM and production conversion.
5. CRM, channel pricing and order state machine.
6. WooCommerce synchronization and idempotent webhooks.
7. Treasury and payment adapters.
8. Consignment/event operations.
9. Logistics adapters.
10. Iranian tax compliance integration.
