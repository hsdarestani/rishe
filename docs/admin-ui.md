# Full WordPress Administration UI

Rishe ERP 1.5.0 adds a shared, responsive and RTL-ready administration console for every implemented bounded context. The console is a WordPress-native client of the existing protected REST APIs; it does not bypass domain validation, transactions, audit recording, immutable ledgers or provider security.

## Module workspaces

- Accounting: chart of accounts, voucher entry, posting, reversal and trial balance.
- Inventory: warehouses, products, batch receipts, reservations, transfers, stock and ledger.
- Manufacturing: BOM versions, components, activation, production execution and order details.
- Sales and CRM: customers, channel prices, promotions, orders, payments, completion and cancellation.
- Treasury: accounts, providers, payment links, imported transactions, matching and settlements.
- Procurement: suppliers, statements, purchase orders, approval, receipts, landed costs and payments.
- B2B and consignment: accounts, credit, dispatches, returns, sales reports and settlements.
- Logistics: carriers, shipments, quotes, booking, tracking, cancellation, costs and settlements.
- Taxpayer system: profiles, encrypted credentials, product mappings, invoices, freeze, submit, inquiry and derived invoices.
- Settings: environment compatibility, runtime errors, integration availability and version information.

The forms include grouped buyer/customer/address data, repeatable voucher/order/BOM/package lines, key-value configuration editors, filters, responsive tables and JSON detail dialogs. All calls use the current WordPress REST nonce and capability checks.

## Activation diagnostics

The bootstrap checks PHP before Composer is loaded. Unsupported PHP therefore produces a readable activation page instead of a Composer exception or parse-related white screen. The activation wrapper catches requirement, database and migration failures, deactivates the plugin and stores a redacted diagnostic record.

Required runtime:

- PHP 8.1 or newer; PHP 8.2 or 8.3 is recommended.
- WordPress 6.5 or newer.
- MySQL 8.0 or MariaDB 10.6 or newer.
- PHP OpenSSL extension.

The Settings workspace exposes `/wp-json/rishe/v1/environment`, including PHP extensions, database engine/version, HTTPS, writable uploads, WooCommerce, Action Scheduler and the latest activation/runtime error.

## Security boundary

The UI never sends provider callbacks and does not expose stored secrets. Secret and private-key inputs are write-only at the application boundary. Mutation controls remain protected by their existing module capabilities; reports use `rishe_view_reports` where the REST endpoint supports it.
