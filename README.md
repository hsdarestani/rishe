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
- Iranian fiscal invoicing, official product mappings, immutable snapshots, RSA signing, submission, inquiry, retries, correction, cancellation, and returns
- Operations control center, durable background jobs, retry backoff, incidents, diagnostics, audit visibility, and safe non-secret configuration import/export

Version `1.1.0` adds the WordPress administration and operational-hardening foundation. The next delivery track is production certification and deployment automation: real provider contracts, MySQL concurrency suites, backup/restore verification, WP-CLI tools, staging promotion, and release packaging.

See `AGENTS.md` for implementation rules and `docs/` for module APIs and invariants.
