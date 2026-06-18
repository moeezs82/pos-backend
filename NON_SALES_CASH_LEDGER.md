# Non-Sales Cash Ledger — Integration Guide

## Architecture decision: Option A (extend the existing double-entry journal)

Your cash-flow truth does **not** live in `cash_transactions`. `CashbookService::dailySummary()`
(the daily cash report) and `LedgerService::getLedger()` (party ledgers) both read from
`journal_entries` + `journal_postings`, keyed on the cash/bank accounts (`1000`, `1010`) and the
`journal_postings.party` morph. `cash_transactions` is only a partial denormalised mirror (e.g.
`syncFromPayment` doesn't even post to the GL).

So a standalone parallel-sum table (naive Option B) would be invisible to the report unless you also
edited `CashbookService` to UNION it in — a second source of truth and real regression risk.

**Chosen approach:** post every entry through `AccountingService::post()` so it flows into the
cash-flow report and party ledgers with zero changes to those services, plus a thin companion table
`cash_ledger_entries` that carries the domain semantics (category enum, `reference_name`) and a 1:1
link to the journal entry. Money truth in the journal; domain truth in one small table.

## GL posting matrix

Cash leg `C` = `1000` (cash) or `1010` (bank), resolved from `method`. Amount `A` (always > 0).
Party is bound to the **contra** (non-cash) leg, matching how AP settlement already posts.

| Category            | Direction | Debit            | Credit           | Party leg |
|---------------------|-----------|------------------|------------------|-----------|
| `QAMETI_PAYMENT`    | out       | 1310 Qameti      | C                | optional  |
| `QAMETI_COLLECTION` | in        | C                | 1310 Qameti      | optional  |
| `LOAN_GIVEN`        | out       | 1300 Loans Recv. | C                | borrower  |
| `LOAN_RECOVERED`    | in        | C                | 1300 Loans Recv. | borrower  |
| `OTHER_EXPENSE`     | out       | 5300 (or override) | C              | optional  |

New accounts seeded by the migration: `1300` Loans Receivable (Asset), `1310` Qameti/Committee
Control (Asset), `5300` Other/Sundry Expense (Expense).

Because LOAN_GIVEN/RECOVERED bind the party to the receivable leg, a loan to a customer shows on that
customer's existing ledger automatically (debit raises what they owe, recovery reduces it). The Qameti
control account's running balance shows the shop's net position across committees.

## Install

1. Copy the files into the matching paths under `app/` and `database/migrations/`.
2. `php artisan migrate` (creates `cash_ledger_entries`, seeds the 3 accounts idempotently).
3. Add `use App\Http\Controllers\Api\V1\CashLedgerController;` to `routes/api.php` and paste the block
   from `routes/cash-ledger-routes.php` inside the `auth:sanctum` + `branch.context` group.
4. If you gate by permission, register `view-cashbook` / `manage-cashbook` for the new routes (reuses
   your existing cashbook permissions).

## Endpoints

- `POST /cash-ledger` — create an entry (validates: amount > 0; `reference_name` required when no
  party; cash-out blocked if it would overdraw the cash drawer unless `allow_negative_cash=true`).
- `GET  /cash-ledger` — history, filterable by `category`, `party_type`+`party_id`, `from`/`to`, `status`.
- `GET  /cash-ledger/{id}` — entry with its journal postings.
- `POST /cash-ledger/{id}/void` — reverses the entry with a balancing journal entry (no hard delete).
- `GET  /cash-ledger/cash-flow?from=&to=&detailed=true` — unified summary + detailed timeline.

`party_type` accepts the friendly aliases `user` | `customer` | `vendor` (mapped to FQCNs internally,
consistent with your existing `journal_postings.party` data).
