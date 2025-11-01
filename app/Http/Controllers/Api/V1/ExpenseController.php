<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\Account;
use App\Models\JournalPosting;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ExpenseController extends Controller
{
    public function store(Request $request, AccountingService $acct)
    {
        $data = $request->validate([
            'branch_id'           => 'nullable|integer|exists:branches,id',
            'txn_date'            => 'nullable|date_format:Y-m-d',
            'reference'           => 'nullable|string|max:191',
            'note'                => 'nullable|string|max:2000',
            'status'              => ['nullable', Rule::in(['approved','draft','pending'])],
            'single_entry'        => 'nullable|boolean',

            // payment resolution
            'payment_account_id'  => 'nullable|integer|exists:accounts,id',
            'method'              => 'nullable|string|in:cash,bank,card,wallet',

            // lines (JSON string or array)
            'lines'               => 'required',
        ]);

        // lines can be array or JSON string
        $lines = is_string($data['lines']) ? json_decode($data['lines'], true) : $data['lines'];
        if (!is_array($lines) || empty($lines)) {
            return ApiResponse::error('Lines must be a non-empty array.', 422);
        }

        // Validate each line: account_id + amount > 0
        foreach ($lines as $idx => $line) {
            if (!isset($line['account_id'], $line['amount'])) {
                return ApiResponse::error("Line #".($idx+1)." must include account_id and amount.", 422);
            }
            if (!Account::whereKey($line['account_id'])->exists()) {
                return ApiResponse::error("Line #".($idx+1).": account not found.", 422);
            }
            $amt = round((float)$line['amount'], 2);
            if ($amt <= 0) {
                return ApiResponse::error("Line #".($idx+1).": amount must be > 0.", 422);
            }
        }

        // Resolve branch / date / mode / status
        $branchId = $data['branch_id'] ?? null;
        $entryDate = $data['txn_date'] ?? Carbon::today()->toDateString();
        $single    = (bool)($data['single_entry'] ?? true);
        $status    = $data['status'] ?? 'approved'; // if you have a status column on journal_entries

        try {
            $result = DB::transaction(function () use ($acct, $branchId, $entryDate, $status, $data, $lines, $single) {
                $userId = auth()->id();
                $memo   = $data['reference'] ?? "Expense Entry";
                // Attach note to memo if provided
                if (!empty($data['note'])) {
                    $memo = trim(($memo ? ($memo.' — ') : '').$data['note']);
                }

                // If your JournalEntry has a 'status' field and you want to set it,
                // you can pass it via reference bag or set after creation.
                // We'll keep it simple: we’ll set it after JE creation if the model has it.

                if ($single) {
                    // ---- ONE entry: multiple expense lines (Dr Expense, Cr Payment total) ----
                    $postings = [];
                    $total = 0.0;
                    foreach ($lines as $line) {
                        $amt = round((float)$line['amount'], 2);
                        $total += $amt;
                        $postings[] = [
                            'account_code' => (int)$line['account_code'], // Expense account
                            'debit'      => $amt,
                            'credit'     => 0,
                        ];
                    }
                    // Credit payment (cash/bank)
                    $postings[] = [
                        'account_code' => 1000,
                        'debit'      => 0,
                        'credit'     => round($total, 2),
                    ];

                    $je = $acct->post(
                        branchId: $branchId,
                        memo:     $memo,
                        reference: null,               // optional
                        lines: $postings,
                        entryDate: $entryDate,
                        userId:    $userId
                    );

                    // Optional status set
                    if (property_exists($je, 'status') && $status) {
                        $je->status = $status;
                        $je->save();
                    }

                    return [
                        'entry'    => $je->toArray(),
                        'postings' => JournalPosting::where('journal_entry_id', $je->id)->get()->toArray(),
                        'total'    => round($total, 2),
                    ];
                }

                // ---- MANY entries: one per line (Dr Expense, Cr Payment) ----
                $out = [];
                foreach ($lines as $line) {
                    $amt = round((float)$line['amount'], 2);

                    $je = $acct->post(
                        branchId: $branchId,
                        memo:     $memo,
                        reference: null,
                        lines: [
                            [
                                'account_code' => (int)$line['account_code'],
                                'debit'      => $amt,
                                'credit'     => 0,
                            ],
                            [
                                'account_code' => 1000,
                                'debit'      => 0,
                                'credit'     => $amt,
                            ],
                        ],
                        entryDate: $entryDate,
                        userId:    $userId
                    );

                    if (property_exists($je, 'status') && $status) {
                        $je->status = $status;
                        $je->save();
                    }

                    $out[] = [
                        'entry'    => $je->toArray(),
                        'postings' => JournalPosting::where('journal_entry_id', $je->id)->get()->toArray(),
                        'total'    => $amt,
                    ];
                }

                return ['entries' => $out];
            });

            return ApiResponse::success($result);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
