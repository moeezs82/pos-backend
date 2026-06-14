# Financial Year Close Command

This project has an Artisan command for closing a financial year by creating a new SQLite database for the next year.

The command does not modify the current live database and does not automatically change `.env`. It creates a new database that contains setup/master data, current stock, and opening balances from the previous year.

The command is branch-safe and supports two modes:

1. **All-branches close** — closes the financial year for every branch.
2. **Specific-branch close** — closes only the selected branch, while all other branches keep their existing transaction/history records in the new database.

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

## Specific-branch close

Close only one branch by ID:

```bash
php artisan financial-year:close 2026 --branch-id=3
```

You can also close by exact branch name:

```bash
php artisan financial-year:close 2026 --branch="Main Branch"
```

For a branch-specific close, the default target path includes the branch ID:

```text
database/financial_years/pos_2027_branch_3_closed.sqlite
```

Important behavior:

- The target database still contains **all branches**.
- The selected branch's old transaction history is not copied.
- The selected branch receives opening journal balances for the new financial year.
- Every other branch's sales, purchases, receipts, cash transactions, stock movements, journal history, and related records are copied as-is.
- When you point `.env` to this new database, Branch 3 will be closed into the new year while all other branches remain exactly as they were.

## Recommended Flow

First run a dry run:

```bash
php artisan financial-year:close 2026 --dry-run
```

For one branch:

```bash
php artisan financial-year:close 2026 --branch-id=3 --dry-run
```

Check the output carefully. It will show:

- source database
- closing date
- scope: all branches or selected branch
- target database path
- master/setup tables copied
- for branch-specific close, transaction/history rows kept for other branches
- opening balance group count
- opening balance raw total

If everything looks correct, create the new database:

```bash
php artisan financial-year:close 2026
```

Or for one branch:

```bash
php artisan financial-year:close 2026 --branch-id=3
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

Master/setup data is always copied for the full company:

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

For a specific-branch close, these master/setup tables are **not reduced to one branch**. They are copied for all branches so the new database remains a complete company database.

## Transaction History Behavior

### All-branches close

Old transaction history is not copied for any branch:

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
- delivery boy received entries

Opening balances are created for every branch.

### Specific-branch close

For the selected branch, old transaction history is not copied and opening balances are created.

For all other branches, old transaction history **is copied as-is** so they continue unchanged.

Example:

```bash
php artisan financial-year:close 2026 --branch-id=1
```

Result:

```text
Branch 1       => closed into opening balances for new year
Branch 2,3,... => all existing records remain available in the new database
```

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

For an all-branches close, one opening journal entry is created per branch with balances.

For a branch-specific close, only the selected branch's journal postings are grouped and carried into the target database.

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

### Close Specific Branch

```bash
php artisan financial-year:close 2026 --branch-id=3
```

or:

```bash
php artisan financial-year:close 2026 --branch="Main Branch"
```

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
- Run this command after all sales, returns, purchases, payments, and adjustments for the closing branch/year are completed.
- The command currently supports SQLite database files.
- The current `.env` is not changed automatically.
- The old database remains available for historical reports and audit checking.
- For branch-specific close, the target database is still a full company database; only the selected branch is financially closed.

## Quick Example

All branches:

```bash
php artisan financial-year:close 2026 --dry-run
php artisan financial-year:close 2026
php artisan config:clear
```

Single branch close while preserving all other branch history:

```bash
php artisan financial-year:close 2026 --branch-id=3 --dry-run
php artisan financial-year:close 2026 --branch-id=3
php artisan config:clear
```
