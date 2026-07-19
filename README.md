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
- Iranian fiscal invoicing, product/service mappings, immutable official snapshots, RSA signing, submission, inquiry, retries, correction, cancellation, and return invoices

Version `1.0.0` completes the ERP domain foundation. The next delivery track is production hardening and administration UX: real-provider certification, WordPress admin screens, background queues, observability, import/export, and deployment tooling.

See `AGENTS.md` for implementation rules and `docs/` for module APIs and invariants.
