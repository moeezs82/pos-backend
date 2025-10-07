<?php

namespace App\Services;

use App\Models\{Account, JournalEntry, JournalPosting};
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingService
{
    public function post(int $branchId, string $memo, $reference, array $lines, ?string $entryDate = null, ?int $userId = null): JournalEntry
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
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->id,
                'created_by'     => $userId,
            ]);

            foreach ($lines as $l) {
                $account = Account::where('code', $l['account_code'])->firstOrFail();
                JournalPosting::create([
                    'journal_entry_id' => $je->id,
                    'account_id' => $account->id,
                    'debit'  => $l['debit']  ?? 0,
                    'credit' => $l['credit'] ?? 0,
                ]);
            }
            return $je;
        });
    }
}
