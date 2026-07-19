# Manufacturing and BOM

The manufacturing bounded context converts inventory components into a finished-goods batch while preserving exact batch cost, waste, labor, overhead, and audit traceability.

## BOM lifecycle

A BOM is created as an immutable versioned draft containing:

- one finished product and a standard output quantity;
- raw-material and packaging components;
- a component quantity for the standard output;
- optional waste basis points for each component;
- optional effective dates.

Activating a draft retires the previous active version with the same BOM code. Active and retired BOM structures cannot be edited. A correction is made by creating and activating a new version.

## Production execution

Production is executed as one database transaction:

1. Lock the active BOM and validate its effective dates.
2. Scale every component requirement to the requested finished quantity.
3. Add standard waste allowance for each component.
4. Lock eligible component batches in the product's FIFO or LIFO order.
5. Deduct raw materials and packaging and record exact batch consumption.
6. Record waste separately from standard material usage.
7. Add labor and overhead to material and waste cost.
8. Create the finished-goods batch using the calculated production unit cost.
9. Record immutable issue, waste, and receipt stock movements.
10. Mark the production order completed and write its audit event.

A production reference is unique. Repeating the same completed reference returns the existing result instead of consuming inventory again.

## Cost convention

Quantities use the inventory scale of four decimal places. Monetary values are non-negative integer Iranian rial amounts.

```text
material cost = sum(standard component quantity × source batch unit cost)
waste cost = sum(waste quantity × source batch unit cost)
total production cost = material + waste + labor + overhead
finished unit cost = total production cost ÷ finished quantity
```

The generated finished batch stores this full unit cost, so later FIFO COGS includes conversion cost.

## REST endpoints

All routes use the `rishe/v1` namespace.

- `POST /manufacturing/boms`
- `GET /manufacturing/boms`
- `POST /manufacturing/boms/{id}/activate`
- `POST /manufacturing/orders/execute`
- `GET /manufacturing/orders`
- `GET /manufacturing/orders/{id}`

Mutation endpoints require `rishe_manage_manufacturing`; reporting endpoints require `rishe_view_reports`.

### BOM example

```json
{
  "code": "RICE-500",
  "name": "Rice 500 gram pack",
  "output_product_id": 100,
  "output_quantity": "10",
  "effective_from": "2026-07-19",
  "components": [
    {
      "product_id": 10,
      "component_type": "raw_material",
      "quantity": "5",
      "waste_basis_points": 250
    },
    {
      "product_id": 20,
      "component_type": "packaging",
      "quantity": "10",
      "waste_basis_points": 100
    }
  ]
}
```

### Production example

```json
{
  "bom_id": 7,
  "input_warehouse_id": 3,
  "output_warehouse_id": 1,
  "output_quantity": "100",
  "output_batch_code": "RICE-500-20260719",
  "output_expiry_date": "2027-07-19",
  "labor_cost_irr": 15000000,
  "overhead_cost_irr": 4000000,
  "reference_type": "production_plan",
  "reference_id": "PLAN-2026-001",
  "correlation_id": "plan-2026-001"
}
```

## Database protection

Database triggers enforce positive quantities, valid waste ratios, valid lifecycle transitions, immutable activated BOM structure, immutable completed production orders, and immutable consumption/output rows. The inventory movement validator is extended for `production_issue`, `production_waste`, and `production_receipt` directions.
