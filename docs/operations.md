# Administration UX and Operations Core

The operations bounded context provides a durable execution queue, retry and incident workflows, health diagnostics, a WordPress administration control center, and safe configuration portability.

## Job lifecycle

- `pending`
- `running`
- `retry_wait`
- `completed`
- `failed`
- `cancelled`

Each job stores its type, business aggregate reference, immutable payload, idempotency key, request hash, priority, attempts, scheduling timestamps, execution lock, result, and last error. Reusing an idempotency key with a different request hash is rejected.

Claiming and finalization are separate short transactions. External tax or carrier requests execute after the claim transaction has committed, preventing a remote timeout from holding ERP database locks.

## Registered handlers

- `tax.submit` — submit a frozen official invoice
- `tax.inquire` — inquire about a submitted official invoice
- `logistics.tracking.refresh` — fetch carrier tracking events

Handlers return a small result summary rather than persisting entire external responses in the generic queue. The owning tax and logistics modules retain their detailed immutable request and response histories.

## Scheduling and retries

The scheduler uses Action Scheduler when `as_schedule_single_action()` is available. Otherwise it schedules a unique WordPress single event with the job id as its argument. Duplicate scheduled events are avoided.

Failures use deterministic exponential backoff beginning at 60 seconds and capped at one hour. A job becomes terminally failed after its configured maximum attempts. Manual retry is permitted only while another configured attempt remains.

## Incidents

A terminal failure creates or reopens a fingerprinted incident. Repeated occurrences increment the same incident instead of creating noise. Incident states are:

- `open`
- `acknowledged`
- `resolved`

Incident identity and occurrence counts are protected by database triggers. Job events cannot be updated or deleted.

## Diagnostics

Diagnostics report:

- PHP and Rishe database versions
- database connectivity
- required ERP tables
- OpenSSL and WordPress authentication salts
- WooCommerce and HTTPS state
- Action Scheduler or WordPress Cron availability
- queue, incident, outbox, rejected-tax-invoice, and delivery-exception metrics

## Configuration packages

Only these non-secret options are portable:

- loyalty policy
- sales accounting mapping
- procurement accounting mapping
- B2B accounting mapping
- logistics accounting mapping
- WooCommerce warehouse id
- system user id

Export packages are normalized and checksummed. Import uses two steps: preview changes, then apply the same package with the preview checksum. Secrets and database-backed provider profiles are rejected by design.

## REST endpoints

All routes use `rishe/v1`.

- `GET /operations/dashboard`
- `GET /operations/diagnostics`
- `GET /operations/jobs`
- `POST /operations/jobs`
- `GET /operations/jobs/{id}`
- `POST /operations/jobs/{id}/retry`
- `POST /operations/jobs/{id}/cancel`
- `GET /operations/incidents`
- `POST /operations/incidents/{id}/acknowledged`
- `POST /operations/incidents/{id}/resolved`
- `POST /operations/incidents/{id}/open`
- `GET /operations/configuration/export`
- `POST /operations/configuration/import`

Queue and incident mutations require `rishe_manage_operations`. Diagnostics also remain protected and are not a public health leak.

## Remaining production validation

Action Scheduler and WordPress Cron execution, long-running HTTP failures, worker overlap, lock recovery, migration triggers, and configuration rollback require smoke and concurrency tests on a real WordPress installation with MySQL 8 or MariaDB 10.6.
