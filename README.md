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

The next milestone is manufacturing and BOM: packaging formulas, material consumption, finished-goods receipts, waste, labor, and conversion cost.

See `AGENTS.md` for Codex implementation rules and the documents under `docs/` for module APIs and invariants.
