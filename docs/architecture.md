# Rishe Architecture

Rishe runs inside WordPress while treating WordPress as runtime, identity provider, administration shell, scheduler host, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code do not call WordPress hooks directly.
- WordPress, WooCommerce, scheduler, carrier, bank, and tax integrations belong under `Infrastructure`.
- Posted financial, inventory, production, sales, treasury, supplier, B2B, logistics, tax, and operational event ledgers are append-only.
- Multi-record mutations execute through the transaction manager.
- External commands and webhooks use idempotency, signatures, immutable snapshots, and audit logs.
- Background network calls do not hold database locks; jobs are claimed in a short transaction, executed outside it, and finalized in a second transaction.

## Implemented bounded contexts

Foundation provides migrations, audit, replay protection, and outbox delivery. Accounting, inventory, manufacturing, sales, treasury, procurement, B2B, logistics, tax, and operations each own dedicated tables and application services.

### Tax compliance

- `rishe_tax_profiles`
- `rishe_tax_product_mappings`
- `rishe_tax_sequences`
- `rishe_tax_invoices`
- `rishe_tax_invoice_lines`
- `rishe_tax_invoice_payments`
- `rishe_tax_submissions`
- `rishe_tax_status_events`

A tax invoice is created from an immutable sales-order snapshot. Freezing allocates a locked serial, generates the tax number, constructs the canonical payload, hashes it, and signs it with the encrypted taxpayer private key. Submission attempts and inquiries append history. Correction, cancellation, and return create linked invoices.

### Operations

- `rishe_operation_jobs`
- `rishe_operation_job_events`
- `rishe_system_incidents`

Operation jobs are idempotent by a stable request key and request hash. A worker claims a pending or retry-wait job with a unique lock token, increments its attempt counter, commits the claim, performs the integration call, and then records completion or failure. Exponential retry backoff is capped, job events are append-only, and terminal failures create or reopen a fingerprinted incident.

The scheduler adapter prefers Action Scheduler when its API is available and otherwise uses a unique WordPress single cron event. Registered handlers currently cover tax submission, tax inquiry, and logistics tracking refresh.

Diagnostics check runtime versions, database connectivity and migration state, required tables, OpenSSL, WordPress salts, WooCommerce, HTTPS, and scheduling availability. The administration UI reads diagnostics, queue metrics, incidents, and recent audit events through protected REST endpoints.

Safe configuration packages contain only an explicit allowlist of non-secret WordPress options. Packages are deterministically normalized, SHA-256 checksummed, previewed before import, and applied only after checksum confirmation. Provider credentials, webhook secrets, private keys, and database-backed integration profiles are excluded.

All tables use the active WordPress database prefix. ERP records remain during normal uninstall to prevent accidental loss of operational or financial history.

## Next production track

Real-provider certification, MySQL concurrency and failure-injection tests, backup/restore verification, WP-CLI operational commands, staging-to-production promotion, signed release packaging, and deployment automation remain the next delivery track.
