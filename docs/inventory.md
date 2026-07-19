# Inventory Core

The inventory bounded context manages quantity and valuation across multiple warehouses with batch-level traceability.

## Quantity convention

Quantities are stored as scaled integers with four decimal places. For example, `2.5 kg` is stored as `25000`. API clients must send quantities as JSON strings or integers to avoid binary floating-point errors.

## Core records

- Warehouses: central, branch, workbench, consignment, or other.
- Products: SKU, base unit, WooCommerce reference, and FIFO/LIFO allocation method.
- Batches: warehouse-specific quantity, reserved quantity, receipt date, expiry date, and IRR unit cost.
- Reservations: idempotent stock holds linked to an external reference such as an order.
- Reservation allocations: exact batch quantities assigned to a reservation.
- Stock movements: immutable receipt, issue, transfer-out, and transfer-in ledger entries.

## Reservation lifecycle

1. Reserve locks eligible batch rows and allocates available quantity in FIFO or LIFO order.
2. Repeating the same external reference and quantity returns the original reservation.
3. Release returns allocated quantities to availability without changing on-hand stock.
4. Commit deducts on-hand and reserved quantities together and records batch-level COGS.

Expired, depleted, or quarantined batches are never allocated.

## Transfers

A transfer deducts available stock from source batches and creates destination batches with the original unit cost, expiry date, batch code, and an `origin_batch_id`. Paired immutable movements share one transfer group UUID.

## REST endpoints

All routes use `rishe/v1` and require inventory or reporting capabilities.

- `POST /inventory/warehouses`
- `POST /inventory/products`
- `POST /inventory/receipts`
- `POST /inventory/reservations`
- `POST /inventory/reservations/{id}/release`
- `POST /inventory/reservations/{id}/commit`
- `POST /inventory/transfers`
- `GET /inventory/stock`
- `GET /inventory/ledger`

### Reservation payload

```json
{
  "product_id": 10,
  "warehouse_id": 2,
  "quantity": "2.5",
  "reference_type": "order",
  "reference_id": "10001",
  "expires_at": "2026-07-19 18:00:00",
  "correlation_id": "order-10001"
}
```
