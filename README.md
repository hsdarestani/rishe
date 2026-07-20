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
composer release:candidate
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
- Release-candidate package installation, production-archive policy checks, and full backup/mutation/restore disaster-recovery rehearsals on MySQL and MariaDB
- Event-driven business analytics, source/campaign attribution, target management, price history, analytical dimensions, daily facts and snapshots, executive dashboards, and alerts
- Full WordPress administration workspaces for accounting, inventory, manufacturing, sales/CRM, treasury, procurement, B2B, logistics, tax and settings

Version `1.5.0` adds guarded activation diagnostics and a responsive WordPress administration workspace for every implemented ERP module. The database schema remains `2026071924` because this release adds no tables or triggers.

See `AGENTS.md` for implementation rules and the documents under `docs/` for module APIs, deployment requirements, invariants, and the remaining PRD gap plan.
