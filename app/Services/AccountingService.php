<?php

namespace App\Services;

use App\Models\{Account, JournalEntry, JournalPosting};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class AccountingService
{
    public function post(?int $branchId, string $memo, $reference = null, array $lines, ?string $entryDate = null, ?int $userId = null): JournalEntry
    {
        // $lines = [['account_code'=>'1200','debit'=>100,'credit'=>0], ...]
        $sumDebit  = collect($lines)->sum('debit');
        $sumCredit = collect($lines)->sum('credit');
        if (bccomp($sumDebit, $sumCredit, 2) !== 0) {
            throw new InvalidArgumentException("Unbalanced entry: debit $sumDebit != credit $sumCredit");
        }

        return DB::transaction(function () use ($branchId, $memo, $reference, $lines, $entryDate, $userId) {
            $je = JournalEntry::create([
                'entry_date' => $entryDate ?? now()->toDateString(),
                'memo'       => $memo,
                'branch_id'  => $branchId,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id'   => $reference->id ?? null,
                'created_by'     => $userId,
            ]);

            foreach ($lines as $l) {
                $account = Account::where('code', $l['account_code'])->firstOrFail();
                JournalPosting::create([
                    'journal_entry_id' => $je->id,
                    'account_id' => $account->id,
                    'debit'  => $l['debit']  ?? 0,
                    'credit' => $l['credit'] ?? 0,
                    'party_type' => $l['party_type'] ?? null,
                    'party_id' => $l['party_id'] ?? null,
                ]);
            }
            return $je;
        });
    }

    /**
     * Convenience: map a UI payment method to an account ID.
     * Change codes as per your chart (e.g., 1000 Cash, 1010 Bank).
     */
    public function paymentAccountIdFromMethod(?string $method): ?int
    {
        if (!$method) return null;

        return match ($method) {
            'cash'   => Account::where('code', '1000')->value('id'),
            'bank'   => Account::where('code', '1010')->value('id'),
            'card'   => Account::where('code', '1010')->value('id'), // or a Card Clearing account
            'wallet' => Account::where('code', '1010')->value('id'), // change to Wallet account if you have one
            default  => null,
        };
    }

    /**
     * Resolve a "party type" string into a fully-qualified model class.
     *
     * Accepts:
     *  - Morph alias (if you use Relation::enforceMorphMap): 'customer', 'vendor'
     *  - Class basename (any case): 'Customer', 'orderItem'
     *  - Snake/plural names: 'order_items', 'customers'
     *  - Fully qualified class: 'App\Models\Customer'
     *
     * Tries (in order):
     *  1) morphMap alias via Relation::getMorphedModel()
     *  2) already-FQCN (class_exists)
     *  3) Candidate FQCNs in common namespaces (App\Models, App\)
     *  4) Studly(Singular($value)) under those namespaces
     *
     * @param  string|null  $value        Incoming type string
     * @param  array        $namespaces   Namespaces to try (first match wins)
     * @return string|null  FQCN or null if not resolvable
     */
    function resolveModelClass(?string $value, array $namespaces = ['App\\Models', 'App']): ?string
    {
        if (!$value) return null;

        $raw = trim($value);

        // 1) Respect morphMap aliases (returns FQCN or null)
        if ($mapped = Relation::getMorphedModel(strtolower($raw))) {
            return ltrim($mapped, '\\');
        }

        // 2) Already a class name?
        if (Str::contains($raw, '\\') && class_exists(ltrim($raw, '\\'))) {
            return ltrim($raw, '\\');
        }

        // Normalize candidate base name: singular + studly case
        // e.g. 'order_items' -> 'OrderItem', 'customers' -> 'Customer'
        $base = Str::studly(Str::singular($raw));

        // 3) Try common namespaces with the given raw (in case they passed 'Customer')
        foreach ($namespaces as $ns) {
            $candidate = trim($ns, '\\') . '\\' . ltrim($raw, '\\');
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // 4) Try common namespaces with the normalized Studly(Singular) base
        foreach ($namespaces as $ns) {
            $candidate = trim($ns, '\\') . '\\' . $base;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // 5) Last-chance: if they passed a plain basename that actually exists in root
        if (class_exists($base)) {
            return ltrim($base, '\\');
        }

        return null;
    }
}
