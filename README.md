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

Plugin bootstrapping, versioned migrations, ERP capabilities, protected REST health checks, transaction handling, audit storage, idempotency storage, integration outbox, coding standards, and CI.

### Accounting core

Four-level chart of accounts, integer-IRR journals, balanced draft vouchers, fiscal-year numbering, posting, immutable entries, reversals, audit events, and trial balance.

### Inventory core

Warehouses, products, scaled decimal quantities, batches, FIFO/LIFO, reservations, immutable movements, batch COGS, transfers, stock summaries, and ledgers.

### Manufacturing and BOM

Versioned formulas, material and packaging requirements, batch consumption, waste, labor and overhead costing, production records, and fully costed finished goods.

### Omnichannel sales and CRM

Unified customers, channel prices, promotions, loyalty, multi-channel orders, WooCommerce webhooks, stock reservation, payment capture, COGS, and sales accounting.

### Treasury and payment links

Bank, cash, POS and gateway accounts, configurable providers, encrypted credentials, payment links, signed callbacks, immutable transactions, settlements, and reconciliation.

### Procurement and accounts payable

Suppliers, purchase orders, partial receipts, landed-cost capitalization, supplier liabilities, treasury-backed payments, accounting, and supplier statements.

### Consignment and B2B settlement

Partner accounts, credit limits, consignment dispatch and return, agent sales reports, commissions, receivables, treasury settlement, and accounting.

### Logistics integrations

Configurable Post, Tipax, Snapp, AloPeyk and custom adapters; shipment and package snapshots; quotes; bookings; labels; tracking; signed webhooks; delivery exceptions; carrier costs; variance; and treasury settlement.

The next milestone is Iranian fiscal invoicing and tax compliance: official invoices, taxpayer-system payloads, signing, submission, retries, corrections, and cancellation.

See `AGENTS.md` for implementation rules and `docs/` for module APIs and invariants.
