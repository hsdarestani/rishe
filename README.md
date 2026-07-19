# Rishe ERP

A modular ERP and omnichannel operations plugin for WordPress and WooCommerce.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Composer 2
- MySQL 8+ or MariaDB 10.6+

## Development

```bash
composer install
composer lint
composer test
```

Install the repository as a WordPress plugin, run `composer install`, and activate **Rishe ERP** from the WordPress administration panel.

## Implemented milestones

### Foundation

Plugin bootstrapping, versioned database migrations, ERP capabilities, a protected health endpoint, transaction handling, audit storage, idempotency storage, an integration outbox, coding standards, and CI.

### Accounting core

A four-level chart of accounts, integer-IRR journal lines, balanced draft vouchers, fiscal-year voucher numbering, final posting, immutable posted entries, reversal vouchers, audit events, and trial-balance reporting.

### Inventory core

Multiple warehouses, products and units, scaled decimal quantities, batch tracking, FIFO/LIFO allocation, idempotent reservations, reservation commit and release, immutable stock movements, COGS by batch, transfers, stock summary, and ledger reporting.

### Manufacturing and BOM

Versioned formulas, raw-material and packaging requirements, FIFO/LIFO batch consumption, explicit waste, labor and overhead costing, immutable production consumption/output records, and finished-goods receipts carrying full production unit cost.

### Omnichannel sales and CRM

Unified mobile-based customers, channel prices, promotions, loyalty, multi-channel orders, WooCommerce webhook ingestion, inventory reservations, captured payments, batch-level COGS, and automatic accounting posting with retry support.

### Treasury and payment links

Bank, cash, POS, and gateway accounts; configurable providers including Blue Business; encrypted credentials; idempotent payment links; signed callbacks; immutable transactions and settlements; reconciliation; and automatic sales-payment matching.

The next milestone is procurement and accounts payable: suppliers, purchase orders, landed costs, receipts, supplier debt, and payment workflows.

See `AGENTS.md` for Codex implementation rules and the documents under `docs/` for module APIs and invariants.
