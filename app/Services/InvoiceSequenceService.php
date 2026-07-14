<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Sale;

/**
 * Collision-safe, branch-local, daily invoice-number allocator.
 *
 * Replaces the global-counter logic that previously lived in
 * SaleController::generateInvoiceNo().  The key difference is that the
 * counter is now scoped by (branch_id, ymd) so each branch maintains its
 * own independent daily sequence:
 *
 *   Branch A  2026-06-20 → INV-20260620-001, INV-20260620-002 …
 *   Branch B  2026-06-20 → INV-20260620-001, INV-20260620-002 …
 *
 * MUST be called inside a DB transaction (SaleController::store() already
 * wraps the whole sale creation in one).  lockForUpdate() on the
 * invoice_sequences row serialises concurrent sales in the same branch/date
 * so they can never receive the same number.
 *
 * The bounded retry loop handles the one narrow race this lock can't cover:
 * the very first sale of a (branch, day) pair, where two concurrent
 * transactions both find no row and both try to seed it.  The UNIQUE
 * constraint on (branch_id, ymd) lets exactly one win; the loser loops
 * back into the locked-increment path.
 */
class InvoiceSequenceService
{
    /**
     * Allocate the next invoice number for the given branch and date.
     *
     * @param  int             $branchId   The branch that owns this sale.
     * @param  \Carbon\Carbon  $forDate    The sale's occurrence date (not now()).
     * @return string                      e.g. "INV-20260714-005"
     *
     * @throws \RuntimeException  If allocation fails after several attempts
     *                            (should not happen in normal operation).
     */
    public function allocate(int $branchId, \Carbon\Carbon $forDate): string
    {
        $datePart = $forDate->format('Ymd');   // "20260714"
        $prefix   = "INV-{$datePart}-";

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // Try to lock the existing counter row for this branch + date.
            $row = DB::table('invoice_sequences')
                ->where('branch_id', $branchId)
                ->where('ymd', $datePart)
                ->lockForUpdate()
                ->first();

            if ($row) {
                // Row exists: atomically hand out next_seq and increment.
                $nextNumber = (int) $row->next_seq;
                DB::table('invoice_sequences')
                    ->where('branch_id', $branchId)
                    ->where('ymd', $datePart)
                    ->update(['next_seq' => $nextNumber + 1, 'updated_at' => now()]);

                return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
            }

            // No row yet for this branch/date.  Seed from any existing sales
            // for this branch + date (covers historical data created before this
            // sequence table shipped, or offline sales synced out of date order).
            $lastInvoice = Sale::withTrashed()       // include soft-deleted rows
                ->where('branch_id', $branchId)
                ->where('invoice_no', 'like', $prefix . '%')
                ->orderByDesc('invoice_no')
                ->value('invoice_no');

            $existingMax = $lastInvoice
                ? (int) substr($lastInvoice, strlen($prefix))
                : 0;
            $nextNumber  = $existingMax + 1;

            try {
                DB::table('invoice_sequences')->insert([
                    'branch_id'  => $branchId,
                    'ymd'        => $datePart,
                    'next_seq'   => $nextNumber + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
            } catch (\Illuminate\Database\QueryException $e) {
                // Another transaction seeded the row first — loop back to the
                // locked-increment path.
                if ($this->isUniqueViolation($e)) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException(
            "Could not allocate invoice number for branch {$branchId} on {$datePart} after 5 attempts."
        );
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
