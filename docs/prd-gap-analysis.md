# Master PRD Coverage and Remaining Delivery Plan

This document compares the July 2026 Master PRD with the implemented WordPress ERP architecture.

## Architecture decision

The PRD proposed Python/Django/FastAPI, PostgreSQL, Redis, containers, and a fully separate ERP service. The implemented product deliberately uses PHP 8.1+, dedicated ERP tables, MySQL 8/MariaDB 10.6, WordPress identity/admin/runtime, and WooCommerce as the commerce surface. Domain/application code remains isolated from WordPress adapters. This is an architecture decision, not an unfinished feature; migrating to Python would be a separate platform rewrite.

## Covered

- four-level double-entry accounting, posted immutability, reversals, trial balance
- multiple warehouses, batch tracking, reservations, transfers, FIFO/LIFO, COGS
- versioned BOM, labor/overhead/waste costing, finished batch costing
- unified mobile-based CRM, omnichannel orders, promotions, loyalty, WooCommerce ingestion
- treasury accounts, payment links, signed callbacks, settlement and reconciliation
- procurement, partial receipts, landed cost, supplier liabilities and payments
- consignment/event dispatch, returns, agent sales, commissions and B2B settlement
- configurable carrier adapters, labels, tracking, signed webhooks, cost reconciliation
- immutable official tax invoices, signing, submission, retry, inquiry, correction/cancellation/return
- durable jobs, retry, incidents, diagnostics, backup/restore, signed releases and deployment certification
- event-driven analytics, source/campaign attribution, targets, price history, dimensions, daily snapshots, executive dashboards and alerts

## Partially covered and scheduled as bounded contexts

1. **Inventory and manufacturing completion**
   - FEFO preference over FIFO
   - multi-output BOM with main product, by-products and zero-value waste
   - automatic reservation expiry/release
   - database/application guard for selling below current COGS

2. **Returns and authorization controls**
   - reverse-logistics disposition to saleable or quarantine warehouse
   - proportional cash/payment/loyalty restoration
   - approval challenges for cash refunds and discounts above policy threshold

3. **Treasury and asset completion**
   - petty-cash statements and replenishment
   - cheque state machine and automatic accounting entries
   - fixed assets, construction-in-progress, depreciation methods and scheduled vouchers

4. **Commerce master data and marketing automation**
   - ERP-to-WooCommerce product, stock and price push
   - blocking direct WooCommerce master-data edits
   - Kavenegar/Faraz pattern SMS adapters
   - abandoned-cart detection and service-SMS recovery workflows

5. **Operator experience**
   - full administration screens for master data and all transaction workflows
   - mobile-first warehouse receiving, issue, transfer and stocktake UI with camera barcode scanning
   - offline POS/PWA with IndexedDB queue, conflict handling and sync

6. **Backup distribution and external certification**
   - AES-256 encrypted off-site backup copies, size chunking, SMTP and private Telegram adapters
   - real taxpayer, payment, SMS and carrier credentials, accepted payloads and callback samples
   - real staging/production SSH deployment and release signing secrets

## Delivery rule

Each remaining area is delivered as a separate bounded-context PR with migrations, immutable corrections, REST/UI, tests, MySQL/MariaDB integration and release-candidate backup/restore certification.
