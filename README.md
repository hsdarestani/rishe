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

## Implemented bounded contexts

- Foundation, audit, idempotency, outbox, migrations, and CI
- Four-level accounting and immutable vouchers
- Batch inventory, FIFO/LIFO, reservations, transfers, and COGS
- Manufacturing, BOM, waste, labor, overhead, and finished-goods costing
- Omnichannel sales, CRM, promotions, loyalty, WooCommerce, and sales accounting
- Treasury, payment links, signed callbacks, settlement, and reconciliation
- Procurement, landed costs, supplier liabilities, and accounts payable
- B2B consignment, agent sales, commissions, credit limits, and settlements
- Logistics carriers, quotes, labels, tracking, webhooks, costs, and settlement
- Iranian fiscal invoicing, immutable official snapshots, RSA signing, submission, inquiry, retries, correction, cancellation, and return invoices
- Administration UX, durable jobs, retries, incidents, diagnostics, and safe configuration portability
- Production certification, WP-CLI operations, verified backups, signed release packages, real MySQL/MariaDB integration tests, protected staging promotion, and rollback-aware deployment automation

Version `1.2.0` adds the production delivery and certification toolchain. Provider credentials and contracts still require account-specific certification before live traffic is enabled.

See `AGENTS.md` for implementation rules and the documents under `docs/` for module APIs, deployment requirements, and invariants.
