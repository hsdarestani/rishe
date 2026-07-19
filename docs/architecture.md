# Rishe Architecture

Rishe runs inside WordPress while treating WordPress as runtime, identity provider, administration shell, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code do not call WordPress hooks directly.
- WordPress, WooCommerce, carrier, bank, and tax integrations belong under `Infrastructure`.
- Posted financial, inventory, production, sales, treasury, supplier, B2B, logistics, and tax ledgers are append-only.
- Multi-record mutations execute through the transaction manager.
- External commands and webhooks use idempotency, signatures, immutable snapshots, and audit logs.

## Implemented bounded contexts

Foundation provides migrations, audit, replay protection, and outbox delivery. Accounting, inventory, manufacturing, sales, treasury, procurement, B2B, and logistics each own dedicated tables and application services.

### Tax compliance

- `rishe_tax_profiles`
- `rishe_tax_product_mappings`
- `rishe_tax_sequences`
- `rishe_tax_invoices`
- `rishe_tax_invoice_lines`
- `rishe_tax_invoice_payments`
- `rishe_tax_submissions`
- `rishe_tax_status_events`

A tax invoice is created from an immutable sales-order snapshot. Freezing allocates a locked serial, generates the 22-character tax number, constructs the canonical header/body/payment payload, hashes it, and signs it with the encrypted taxpayer private key. Submission attempts and status inquiries append history instead of replacing it. Correction, cancellation, and return create linked invoices and never rewrite the source invoice.

The HTTP/JSON gateway is configurable because direct taxpayer submission and trusted-provider contracts may expose different endpoints, envelopes, headers, and response paths. Credentials and private keys are encrypted at rest.

All tables use the active WordPress database prefix. ERP records remain during normal uninstall to prevent accidental loss of operational or financial history.

## Production hardening track

Provider certification, real MySQL concurrency tests, WordPress administration screens, Action Scheduler queues, observability, import/export, backup verification, and deployment automation remain outside the domain-core milestone.
