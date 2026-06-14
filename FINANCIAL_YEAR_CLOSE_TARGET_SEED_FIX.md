# Financial Year Close Target Seed Fix

## Problem

`financial-year:close` runs migrations on the new SQLite target database before copying source data. Some migrations can insert setup rows into the fresh target database, for example:

- `account_types` row `ASSET`
- delivery boy cash-in-transit account setup

After that, the close command copied the same source master records with their original IDs. SQLite then failed with a unique constraint error such as:

```text
UNIQUE constraint failed: account_types.id
```

## Fix

`CloseFinancialYear::copyMasterTables()` now clears each target master/setup table before inserting source records.

This keeps the correct behavior:

- target schema is still created through migrations
- source IDs are preserved exactly
- migration-seeded placeholder/setup rows are removed before source rows are copied
- financial year close remains safe for branch-specific close

## Run

```bash
php artisan optimize:clear
php artisan financial-year:close 2026 --branch-id=2 --force
```

The XAMPP warnings about `pdo_firebird`, `pdo_oci`, and duplicate `openssl` are unrelated to this close-command error.
