# Financial Year Close Command

This project has an Artisan command for closing a financial year by creating a new SQLite database for the next year.

The command does not modify the current live database and does not automatically change `.env`. It creates a fresh database that contains setup/master data, current stock, and opening balances from the previous year.

## Command

```bash
php artisan financial-year:close {year}
```

Example:

```bash
php artisan financial-year:close 2026
```

This closes the year up to `2026-12-31` and creates:

```text
database/financial_years/pos_2027.sqlite
```

## Recommended Flow

First run a dry run:

```bash
php artisan financial-year:close 2026 --dry-run
```

Check the output carefully. It will show:

- source database
- closing date
- target database path
- master tables that will be copied
- opening balance group count
- opening balance raw total

If everything looks correct, create the new database:

```bash
php artisan financial-year:close 2026
```

After the command finishes, update `.env` only when you are ready to start working in the new financial year:

```env
DB_DATABASE=C:\xampp\htdocs\pos-backend\database\financial_years\pos_2027.sqlite
```

Then clear Laravel config:

```bash
php artisan config:clear
```

## What Gets Copied

The new database keeps master/setup data:

- account types
- accounts
- payment method accounts
- branches
- users
- roles and permissions
- categories
- brands
- customers
- vendors
- products
- product stocks

## What Does Not Get Copied

Old transaction history is not copied:

- sales
- sale items
- sale returns
- purchases
- purchase claims
- receipts
- vendor payments
- cash transactions
- stock movements
- old journal entries
- old journal postings

This keeps the new year database smaller and cleaner.

## Opening Balances

The command creates opening journal entries dated on the closing date, for example:

```text
2026-12-31
```

This allows reports in the new year to calculate opening balances correctly when starting from:

```text
2027-01-01
```

Balance sheet accounts are carried forward. Income and expense accounts are closed into retained earnings.

Default retained earnings account:

```text
3100 Retained Earnings
```

To use a different retained earnings account:

```bash
php artisan financial-year:close 2026 --retained-earnings=YOUR_ACCOUNT_CODE
```

## Options

### Custom Closing Date

By default, the closing date is `{year}-12-31`.

Use `--date` if your financial year closes on a different date:

```bash
php artisan financial-year:close 2026 --date=2026-06-30
```

The date must be inside the year being closed.

### Custom Target Database

```bash
php artisan financial-year:close 2026 --target=database/financial_years/my_2027.sqlite
```

### Overwrite Existing Target

If the target database already exists, the command will stop for safety.

Use `--force` only when you are sure you want to replace the existing target file:

```bash
php artisan financial-year:close 2026 --force
```

### Dry Run

Dry run does not create or change any database:

```bash
php artisan financial-year:close 2026 --dry-run
```

## Important Notes

- Take a backup of the current database before closing the year.
- Run this command after all sales, returns, purchases, payments, and adjustments for the closing year are completed.
- The command currently supports SQLite database files.
- The current `.env` is not changed automatically.
- The old database remains available for historical reports and audit checking.

## Quick Example

```bash
php artisan financial-year:close 2026 --dry-run
php artisan financial-year:close 2026
php artisan config:clear
```

Then update `.env` to point to:

```text
C:\xampp\htdocs\pos-backend\database\financial_years\pos_2027.sqlite
```
