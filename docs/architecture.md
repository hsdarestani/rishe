# Rishe Foundation Architecture

Rishe runs inside WordPress but treats WordPress as the runtime, identity provider, administration shell, and integration surface. ERP state is stored in dedicated relational tables.

## Boundaries

- Domain and application code must not call WordPress hooks directly.
- WordPress and WooCommerce integration belongs under `Infrastructure`.
- Financial and stock ledgers are append-only after posting.
- Cross-module side effects use audited application services and, where asynchronous delivery is needed, the outbox table.
- Every use case that mutates more than one ERP record must execute through the transaction manager.

## Foundation tables

- `rishe_migrations`: applied schema migrations.
- `rishe_audit_log`: immutable operational and security audit events.
- `rishe_idempotency_keys`: duplicate and replay protection for external commands and webhooks.
- `rishe_outbox`: reliable publication of integration events.

All table names are prefixed with the active WordPress database prefix. ERP tables are retained during normal plugin uninstall to prevent accidental loss of financial records.

## Next bounded context

Accounting will introduce chart-of-account tables, journal vouchers, journal entries, posting and reversal services, trial balance queries, and transaction-level balancing guarantees.
