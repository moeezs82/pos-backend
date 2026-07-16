<?php

namespace App\Services;

use App\Enums\CashLedgerCategory;
use App\Models\Account;
use App\Models\CashLedgerEntry;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\RegisterShift;

class CashLedgerService
{
    /** Payment method => cash/bank GL account code. */
    private const METHOD_TO_CODE = [
        'cash'   => '1000',
        'bank'   => '1010',
        'card'   => '1010',
        'wallet' => '1010',
    ];

    /** Allowed FQCN party types, matching existing journal_postings data. */
    private const PARTY_TYPES = [
        \App\Models\User::class,
        \App\Models\Customer::class,
        \App\Models\Vendor::class,
    ];

    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * Create a non-sales cash entry: write the domain row AND post the balanced
     * journal entry that makes it show up in the cash-flow report and (for loans)
     * the party ledger. Both happen in one DB transaction.
     *
     * @param array{
     *   category: string,
     *   amount: float|int|string,
     *   branch_id?: int|null,
     *   txn_date?: string|null,
     *   method?: string|null,
     *   party_type?: string|null,
     *   party_id?: int|null,
     *   reference_name?: string|null,
     *   note?: string|null,
     *   expense_account_code?: string|null,  // OTHER_EXPENSE override
     *   allow_negative_cash?: bool,
     *   created_by?: int|null,
     * } $data
     */
    public function create(array $data): CashLedgerEntry
    {
        $category = $data['category'] instanceof CashLedgerCategory
            ? $data['category']
            : CashLedgerCategory::from($data['category']);

        $amount = $this->normalizeAmount($data['amount']);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $txnDate  = $data['txn_date'] ?? now()->toDateString();
        $method   = $data['method'] ?? 'cash';
        $userId   = $data['created_by'] ?? auth()->id();
        $registerShiftId = $data['register_shift_id'] ?? RegisterShift::query()
            ->where('cashier_id', $userId)->where('branch_id', $branchId)->where('status', 'open')->value('id');

        $cashCode   = $this->cashAccountCodeForMethod($method, $branchId);
        $cashAcct   = $this->accountByCode($cashCode);
        $contraCode = $category === CashLedgerCategory::OTHER_EXPENSE && !empty($data['expense_account_code'])
            ? (string) $data['expense_account_code']
            : $category->contraAccountCode();
        $this->accountByCode($contraCode); // validate it exists

        [$partyType, $partyId] = $this->resolveParty($data, $category);

        // Mandatory identity when no party is linked.
        $referenceName = $data['reference_name'] ?? null;
        if (!$partyType && ($referenceName === null || trim($referenceName) === '')) {
            throw ValidationException::withMessages([
                'reference_name' => ['A reference name is required when no party is linked.'],
            ]);
        }

        // Safe-limit guard for cash-out from the physical cash drawer.
        if (!$category->isInflow()) {
            $this->assertCashOutWithinLimit($cashAcct, $branchId, $amount, (bool) ($data['allow_negative_cash'] ?? false));
        }

        return DB::transaction(function () use (
            $category, $amount, $branchId, $txnDate, $method, $userId, $registerShiftId,
            $cashCode, $cashAcct, $contraCode, $partyType, $partyId, $referenceName, $data
        ): CashLedgerEntry {
            $entry = CashLedgerEntry::create([
                'txn_date'       => $txnDate,
                'branch_id'      => $branchId,
                'register_shift_id' => $registerShiftId,
                'category'       => $category->value,
                'direction'      => $category->direction(),
                'amount'         => $amount,
                'account_id'     => $cashAcct->id,
                'method'         => $method,
                'party_type'     => $partyType,
                'party_id'       => $partyId,
                'reference_name' => $referenceName,
                'note'           => $data['note'] ?? null,
                'status'         => 'posted',
                'created_by'     => $userId,
            ]);

            $journal = $this->accounting->post(
                branchId: $branchId,
                memo:     $this->buildMemo($category, $referenceName, $partyId, $data['note'] ?? null),
                reference: $entry,
                lines:    $this->buildLines($category, $amount, $cashCode, $contraCode, $partyType, $partyId),
                entryDate: $txnDate,
                userId:   $userId,
            );

            $entry->update(['journal_entry_id' => $journal->id]);

            return $entry->fresh();
        });
    }

    /**
     * Void a posted entry by writing a reversing journal entry (debits/credits
     * swapped). The original audit row is preserved; nothing is hard-deleted.
     */
    public function void(CashLedgerEntry $entry, ?int $userId = null): CashLedgerEntry
    {
        if ($entry->isVoided()) {
            throw ValidationException::withMessages(['status' => ['Entry is already voided.']]);
        }

        return DB::transaction(function () use ($entry, $userId): CashLedgerEntry {
            $original = $entry->journalEntry()->with('postings.account')->first();

            if ($original) {
                $lines = $original->postings->map(fn ($p) => [
                    'account_code' => $p->account->code,
                    'debit'        => (float) $p->credit, // swapped
                    'credit'       => (float) $p->debit,
                    'party_type'   => $p->party_type,
                    'party_id'     => $p->party_id,
                ])->all();

                $reversal = $this->accounting->post(
                    branchId: $entry->branch_id,
                    memo:     'Reversal of cash ledger entry #' . $entry->id,
                    reference: $entry,
                    lines:    $lines,
                    entryDate: now()->toDateString(),
                    userId:   $userId ?? auth()->id(),
                );

                $entry->reversal_entry_id = $reversal->id;
            }

            $entry->status    = 'void';
            $entry->voided_by = $userId ?? auth()->id();
            $entry->save();

            return $entry->fresh();
        });
    }

    /**
     * Filtered, paginated history.
     *
     * @param array{
     *   branch_id?: int|null, category?: string|null,
     *   party_type?: string|null, party_id?: int|null,
     *   from?: string|null, to?: string|null,
     *   status?: string|null, page?: int, per_page?: int
     * } $filters
     */
    public function list(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        $query = CashLedgerEntry::query()
            ->with('party')
            ->forBranch(isset($filters['branch_id']) ? (int) $filters['branch_id'] : null)
            ->category($filters['category'] ?? null)
            ->party($filters['party_type'] ?? null, isset($filters['party_id']) ? (int) $filters['party_id'] : null)
            ->dateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('txn_date')
            ->orderByDesc('id');

        $page = $query->paginate($perPage);

        return [
            'items'        => $page->items(),
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ];
    }

    // ----------------------------------------------------------------
    // internals
    // ----------------------------------------------------------------

    private function buildLines(
        CashLedgerCategory $category,
        float $amount,
        string $cashCode,
        string $contraCode,
        ?string $partyType,
        ?int $partyId
    ): array {
        $cashLine   = ['account_code' => $cashCode,   'debit' => 0.0,    'credit' => 0.0];
        $contraLine = ['account_code' => $contraCode, 'debit' => 0.0,    'credit' => 0.0,
                       'party_type' => $partyType, 'party_id' => $partyId];

        if ($category->isInflow()) {
            // Cash IN: Dr Cash / Cr Contra
            $cashLine['debit']    = $amount;
            $contraLine['credit'] = $amount;
        } else {
            // Cash OUT: Dr Contra / Cr Cash
            $contraLine['debit'] = $amount;
            $cashLine['credit']  = $amount;
        }

        return [$contraLine, $cashLine];
    }

    private function buildMemo(CashLedgerCategory $category, ?string $reference, ?int $partyId, ?string $note): string
    {
        $who = $reference ?: ($partyId ? "party #{$partyId}" : null);
        $memo = $category->label() . ($who ? " — {$who}" : '');
        if ($note) {
            $memo .= ' — ' . $note;
        }
        return mb_substr($memo, 0, 191);
    }

    private function resolveParty(array $data, CashLedgerCategory $category): array
    {
        $type = $data['party_type'] ?? null;
        $id   = isset($data['party_id']) ? (int) $data['party_id'] : null;

        if (!$type || !$id) {
            return [null, null];
        }

        if (!in_array($type, self::PARTY_TYPES, true)) {
            throw ValidationException::withMessages([
                'party_type' => ['Party must be one of: User, Customer, Vendor.'],
            ]);
        }

        // Confirm the bound record actually exists.
        if (!$type::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'party_id' => ['The selected party does not exist.'],
            ]);
        }

        return [$type, $id];
    }

    private function cashAccountCodeForMethod(string $method, ?int $branchId = null): string
    {
        // Prefer the branch's configured payment-method account (Cash, Bank,
        // KNET Clearing, …). Fall back to the legacy fixed map so historical
        // callers without configuration keep working.
        try {
            return app(PaymentMethodService::class)->accountFor($branchId, $method)->code;
        } catch (\Throwable $e) {
            return self::METHOD_TO_CODE[$method] ?? throw ValidationException::withMessages([
                'method' => ["Unsupported payment method [{$method}]."],
            ]);
        }
    }

    private function accountByCode(string $code): Account
    {
        return Account::where('code', $code)->first()
            ?? throw ValidationException::withMessages([
                'account' => ["Required account [{$code}] is missing from the chart of accounts."],
            ]);
    }

    private function normalizeAmount(float|int|string $amount): float
    {
        $value = round((float) $amount, 2);
        if ($value <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }
        return $value;
    }

    /**
     * Block a cash-out that would drive the physical cash drawer negative,
     * unless explicitly overridden. Balance is read from the journal (the
     * single source of truth), so it already reflects sales/purchases/expenses.
     */
    private function assertCashOutWithinLimit(Account $cashAcct, ?int $branchId, float $amount, bool $allowNegative): void
    {
        // Only guard physical cash by default; bank can legitimately be overdrawn-pending.
        if ($allowNegative || $cashAcct->code !== '1000') {
            return;
        }

        $balance = (float) DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->where('jp.account_id', $cashAcct->id)
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(jp.debit - jp.credit), 0) as bal')
            ->value('bal');

        if ($amount > $balance + 1e-6) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'Insufficient cash on hand. Available: %.2f, requested: %.2f. Pass allow_negative_cash=true to override.',
                    $balance,
                    $amount
                )],
            ]);
        }
    }
}
