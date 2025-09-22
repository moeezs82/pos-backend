<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\CashTransaction;
use App\Services\CashSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashBookController extends Controller
{
    public function __construct(private CashSyncService $service) {}

    /**
     * Ledger with opening/closing + running balance (paginated)
     * account_id is optional (all accounts if omitted)
     *
     * Query params:
     * - account_id (optional)  -> when omitted, includes all accounts
     * - branch_id (optional)
     * - date_from (optional)   -> if omitted, no lower bound
     * - date_to (optional)     -> if omitted, no upper bound
     * - status: pending|approved|void (default: approved)
     * - source: sales|purchases (optional)
     * - type: receipt|payment|expense|transfer_in|transfer_out (optional)
     * - method: cash|card|bank|wallet... (optional)
     * - amount_min, amount_max (optional)
     * - search (reference|voucher_no|note) (optional)
     * - per_page (default 50, max 200), page
     */
    public function index(Request $request)
    {
        $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'branch_id'  => ['nullable', 'exists:branches,id'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'     => ['nullable', Rule::in(['pending', 'approved', 'void'])],
            'source'     => ['nullable', Rule::in(['sales','purchases'])],
            'type'       => ['nullable', Rule::in(['receipt','payment','expense','transfer_in','transfer_out'])],
            'method'     => ['nullable', 'string', 'max:50'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $accountId = $request->account_id ? (int)$request->account_id : null;
        $branchId  = $request->branch_id ? (int)$request->branch_id : null;
        $from      = $request->date_from; // nullable
        $to        = $request->date_to;   // nullable
        $status    = $request->status ?? 'approved';
        $source    = $request->source;
        $type      = $request->type;
        $method    = $request->method;
        $amtMin    = $request->amount_min;
        $amtMax    = $request->amount_max;
        $search    = $request->get('search');
        $perPage   = (int) ($request->get('per_page', 50));
        $perPage   = max(1, min($perPage, 200));

        // Base query (ASC for meaningful running balance)
        $base = CashTransaction::query()
            ->with(['counterparty'])
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
            ->when($from && $to, fn($q) => $q->whereBetween('txn_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('txn_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('txn_date', '<=', $to))
            ->when($status,   fn($q) => $q->where('status', $status))
            ->when($source === 'sales', fn($q) => $q->where('source_type', \App\Models\Payment::class))
            ->when($source === 'purchases', fn($q) => $q->where('source_type', \App\Models\PurchasePayment::class))
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($method, fn($q) => $q->where('method', $method))
            ->when(isset($amtMin), fn($q) => $q->where('amount', '>=', $amtMin))
            ->when(isset($amtMax), fn($q) => $q->where('amount', '<=', $amtMax))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('reference', 'like', "%$search%")
                       ->orWhere('voucher_no', 'like', "%$search%")
                       ->orWhere('note', 'like', "%$search%");
                });
            })
            ->orderBy('txn_date', 'asc')
            ->orderBy('id', 'asc');

        // Overall totals for the whole filtered set (not just current page)
        $overallIn  = (clone $base)->whereIn('type',['receipt','transfer_in'])->sum('amount');
        $overallOut = (clone $base)->whereIn('type',['payment','expense','transfer_out'])->sum('amount');
        $overallNet = (float)$overallIn - (float)$overallOut;

        // Opening balance logic:
        // If date_from provided: opening = net of all approved rows BEFORE date_from (same account/branch scope)
        // If no date_from: opening = 0 (we're showing full history)
        if ($from) {
            $openingQuery = CashTransaction::query()
                ->when($accountId, fn($q) => $q->where('account_id', $accountId))
                ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
                ->where('status', $status) // keep same status filter
                ->where('txn_date', '<', $from);

            $openIn  = (clone $openingQuery)->whereIn('type',['receipt','transfer_in'])->sum('amount');
            $openOut = (clone $openingQuery)->whereIn('type',['payment','expense','transfer_out'])->sum('amount');
            $opening = (float)$openIn - (float)$openOut;
        } else {
            $opening = 0.0;
        }

        $closing = (float)$opening + (float)$overallNet;

        // Paginate
        $paginator  = (clone $base)->paginate($perPage);
        $items      = $paginator->items();

        if (empty($items)) {
            return ApiResponse::success([
                'opening_balance' => number_format($opening, 2, '.', ''),
                'inflow'          => number_format($overallIn, 2, '.', ''),
                'outflow'         => number_format($overallOut, 2, '.', ''),
                'net_change'      => number_format($overallNet, 2, '.', ''),
                'closing_balance' => number_format($closing, 2, '.', ''),
                'page_inflow'     => number_format(0, 2, '.', ''),
                'page_outflow'    => number_format(0, 2, '.', ''),
                'transactions'    => [],
                'pagination'      => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ]);
        }

        // Compute prefix sums for rows BEFORE this page to start running balance
        /** @var \App\Models\CashTransaction $first */
        $first = $items[0];
        $prefix = CashTransaction::query()
            ->when($accountId, fn($q) => $q->where('account_id', $accountId))
            ->when($branchId,  fn($q) => $q->where('branch_id', $branchId))
            ->when($from && $to, fn($q) => $q->whereBetween('txn_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('txn_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('txn_date', '<=', $to))
            ->where('status', $status)
            ->when($source === 'sales', fn($q) => $q->where('source_type', \App\Models\Payment::class))
            ->when($source === 'purchases', fn($q) => $q->where('source_type', \App\Models\PurchasePayment::class))
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($method, fn($q) => $q->where('method', $method))
            ->when(isset($amtMin), fn($q) => $q->where('amount', '>=', $amtMin))
            ->when(isset($amtMax), fn($q) => $q->where('amount', '<=', $amtMax))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('reference', 'like', "%$search%")
                       ->orWhere('voucher_no', 'like', "%$search%")
                       ->orWhere('note', 'like', "%$search%");
                });
            })
            ->where(function($q) use ($first) {
                $q->where('txn_date', '<', $first->txn_date)
                  ->orWhere(function($qq) use ($first) {
                      $qq->where('txn_date', $first->txn_date)
                         ->where('id', '<', $first->id);
                  });
            });

        $prefixIn  = (clone $prefix)->whereIn('type', ['receipt','transfer_in'])->sum('amount');
        $prefixOut = (clone $prefix)->whereIn('type', ['payment','expense','transfer_out'])->sum('amount');

        $running = (float)$opening + ((float)$prefixIn - (float)$prefixOut);

        // Build page rows with running balance
        $rows       = [];
        $pageInflow = 0.0;
        $pageOutflow= 0.0;

        foreach ($items as $t) {
            /** @var \App\Models\CashTransaction $t */
            $delta = $t->isInflow() ? (float)$t->amount : -(float)$t->amount;
            $running += $delta;
            if ($delta > 0) $pageInflow += (float)$t->amount; else $pageOutflow += (float)$t->amount;

            $rows[] = [
                'id'               => $t->id,
                'date'             => $t->txn_date->toDateString(),
                'type'             => $t->type,
                'amount'           => number_format((float)$t->amount, 2, '.', ''),
                'method'           => $t->method,
                'reference'        => $t->reference,
                'voucher_no'       => $t->voucher_no,
                'note'             => $t->note,
                'status'           => $t->status,
                'source'           => class_basename($t->source_type),
                'source_id'        => $t->source_id,
                'counterparty'     => $t->counterparty?->only(['id','first_name','last_name','name']),
                'running_balance'  => number_format($running, 2, '.', ''),
            ];
        }

        return ApiResponse::success([
            // Overall (whole filtered range)
            'opening_balance' => number_format($opening, 2, '.', ''),
            'inflow'          => number_format($overallIn, 2, '.', ''),
            'outflow'         => number_format($overallOut, 2, '.', ''),
            'net_change'      => number_format($overallNet, 2, '.', ''),
            'closing_balance' => number_format($closing, 2, '.', ''),

            // Page-only totals
            'page_inflow'     => number_format($pageInflow, 2, '.', ''),
            'page_outflow'    => number_format($pageOutflow, 2, '.', ''),

            // Page data
            'transactions'    => $rows,
            'pagination'      => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Create a direct EXPENSE entry in cash book
     * Either send account_id OR method (which will map to an account).
     * (So yes, you can do it WITHOUT account_id — just pass method.)
     */
    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'method'     => ['nullable', 'string', 'max:50'], // required if account_id is null
            'amount'     => ['required', 'numeric', 'min:0.01'],
            'txn_date'   => ['nullable', 'date'],
            'branch_id'  => ['nullable', 'exists:branches,id'],
            'reference'  => ['nullable', 'string', 'max:190'],
            'note'       => ['nullable', 'string'],
            'status'     => ['nullable', Rule::in(['pending', 'approved'])],
            'counterparty_type' => ['nullable', 'string'],   // e.g. "App\\Models\\Vendor"
            'counterparty_id'   => ['nullable', 'integer'],
        ]);

        if (empty($data['account_id']) && empty($data['method'])) {
            return ApiResponse::error('Either account_id or method is required.', 422);
        }

        $txn = $this->service->createExpense($data);

        return ApiResponse::success($txn, 201);
    }
}
