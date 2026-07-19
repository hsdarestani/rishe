# Iranian Fiscal Invoicing and Taxpayer-System Integration

## Scope

The tax bounded context manages taxpayer profiles, fiscal-memory identifiers, product/service mappings, immutable electronic invoice snapshots, the 22-character tax number, RSA signatures, submission attempts, inquiry results, correction invoices, cancellation invoices, and sales returns.

The regulatory document describes a fixed tax-number structure: six-character fiscal-memory id, five-character hexadecimal registration date, ten-character hexadecimal internal serial, and a Verhoeff check digit. Rishe keeps serial allocation transactional and unique per profile and fiscal year.

## Invoice lifecycle

- `draft`
- `frozen`
- `submitted`
- `accepted`
- `rejected`
- `corrected`
- `cancelled`
- `returned`

Drafts are built from a completed or active sales order. Freezing stores seller, buyer, products, units, quantities, prices, discounts, VAT, payment data, totals, tax number, canonical JSON, SHA-256 hash, and signature. Frozen content cannot be edited.

Rejected invoices may be retried without changing their tax number or payload. Each request and response receives an immutable submission row. Inquiry responses append status events and preserve the complete audit trail.

## Derived invoices

Subjects use the standard numeric categories:

- `1`: original
- `2`: correction
- `3`: cancellation
- `4`: return from sale

A derived invoice references the source tax number and copies its frozen commercial snapshot. After the derived invoice is accepted, the source is marked corrected, cancelled, or returned. Direct mutation of the source is prohibited.

## Canonical payload

The payload contains `header`, `body`, and `payments`. Header fields include tax number, millisecond timestamps, type, pattern, subject, seller and buyer identities, totals, and settlement split. Body rows include product/service id, description, unit, scaled quantity, fee, discount, VAT rate, VAT amount, duties, and final row total.

Product mappings are profile-specific and require:

- Rishe product id
- official product/service identifier
- official measurement-unit code
- VAT rate in basis points

## Security and transport

Private keys and gateway credentials are encrypted using AES-256-GCM and WordPress installation salts. Payloads are signed with RSA-SHA256 as compact JWS. No private key or credential is returned from REST APIs or audit records.

The transport Adapter is configured per taxpayer or trusted provider with submit and inquiry endpoints, methods, headers, credential-header mapping, request root, timeout, and response paths. This avoids embedding undocumented account-specific endpoints or response envelopes in the domain.

## REST endpoints

All routes use `rishe/v1`.

- `POST /tax/profiles`
- `GET /tax/profiles`
- `GET /tax/profiles/{id}`
- `POST /tax/product-mappings`
- `GET /tax/profiles/{id}/product-mappings`
- `POST /tax/invoices`
- `GET /tax/invoices`
- `GET /tax/invoices/{id}`
- `POST /tax/invoices/{id}/freeze`
- `POST /tax/invoices/{id}/submit`
- `POST /tax/invoices/{id}/inquire`
- `POST /tax/invoices/{id}/correction`
- `POST /tax/invoices/{id}/cancellation`
- `POST /tax/invoices/{id}/return`

Mutations require `rishe_manage_tax`; reports require `rishe_view_reports`.

## Remaining certification

The schema and Adapter must be validated with the taxpayer's current official technical package or trusted-provider contract, production certificate chain, server public key requirements, accepted sample invoices, error catalog, and real inquiry responses. Migrations and triggers also require smoke tests on WordPress with MySQL 8 or MariaDB 10.6.
