# Accounting Core

The accounting bounded context implements a four-level chart of accounts and immutable double-entry journal posting.

## Chart of accounts

1. Account group
2. General ledger
3. Subsidiary ledger
4. Floating detail

Account codes are unique within their table. Subsidiary ledgers can require a floating detail for every journal entry.

## Voucher lifecycle

- `draft`: editable through controlled application use cases.
- `temporary`: reserved for reviewed but not final vouchers.
- `posted`: assigned a sequential number inside its fiscal year and made immutable.
- `reversed`: the original posted voucher remains in the ledger and a new posted voucher records the opposite entries.

Posted entries cannot be updated or deleted directly because database triggers guard the journal-entry table.

## Monetary convention

All debit and credit amounts are non-negative integers in Iranian rial. Every journal line contains exactly one positive side, and every voucher must have equal non-zero debit and credit totals.

## REST endpoints

All routes use the `rishe/v1` namespace and require either `rishe_manage_accounting` or `rishe_view_reports`.

- `POST /accounting/account-groups`
- `POST /accounting/general-ledgers`
- `POST /accounting/subsidiary-ledgers`
- `POST /accounting/floating-details`
- `GET /accounting/chart`
- `POST /accounting/vouchers`
- `POST /accounting/vouchers/{id}/post`
- `POST /accounting/vouchers/{id}/reverse`
- `GET /accounting/trial-balance?from=YYYY-MM-DD&to=YYYY-MM-DD`

### Draft voucher payload

```json
{
  "fiscal_year": 1405,
  "voucher_date": "2026-07-19",
  "description": "Cash sale",
  "correlation_id": "order-10001",
  "lines": [
    {
      "subsidiary_ledger_id": 10,
      "floating_detail_id": 42,
      "debit": 1000000,
      "credit": 0,
      "description": "Cash received"
    },
    {
      "subsidiary_ledger_id": 20,
      "floating_detail_id": null,
      "debit": 0,
      "credit": 1000000,
      "description": "Sales revenue"
    }
  ]
}
```

The API creates only a draft. Posting is a separate explicit command and performs a fresh locked balance validation before allocating a voucher number.
