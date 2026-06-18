<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashLedgerEntryRequest;
use App\Http\Response\ApiResponse;
use App\Models\CashLedgerEntry;
use App\Services\BranchContextService;
use App\Services\CashLedgerService;
use App\Services\Reports\UnifiedCashFlowService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashLedgerController extends Controller
{
    public function __construct(
        private readonly CashLedgerService $service,
        private readonly BranchContextService $branches,
    ) {}

    /** POST /cash-ledger */
    public function store(CashLedgerEntryRequest $request)
    {
        $branchId = $this->branches->requireBranchId($request);

        try {
            $entry = $this->service->create($request->toServicePayload($branchId));
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed.', 422, $e->errors());
        }

        return ApiResponse::success($entry->load('party'), 'Cash ledger entry recorded.', 201);
    }

    /** GET /cash-ledger */
    public function index(Request $request)
    {
        $branchId = $this->branches->effectiveBranchId($request);

        $partyType = $request->input('party_type');
        $partyMap  = ['user' => \App\Models\User::class, 'customer' => \App\Models\Customer::class, 'vendor' => \App\Models\Vendor::class];

        $result = $this->service->list([
            'branch_id'  => $branchId,
            'category'   => $request->input('category'),
            'party_type' => $partyType ? ($partyMap[$partyType] ?? null) : null,
            'party_id'   => $request->input('party_id'),
            'from'       => $request->input('from'),
            'to'         => $request->input('to'),
            'status'     => $request->input('status'),
            'page'       => (int) $request->input('page', 1),
            'per_page'   => (int) $request->input('per_page', 15),
        ]);

        return ApiResponse::success($result);
    }

    /** GET /cash-ledger/{entry} */
    public function show(CashLedgerEntry $entry)
    {
        return ApiResponse::success($entry->load(['party', 'journalEntry.postings.account']));
    }

    /** POST /cash-ledger/{entry}/void */
    public function void(Request $request, CashLedgerEntry $entry)
    {
        $this->branches->assertCanAccessBranch($request, $entry->branch_id);

        try {
            $voided = $this->service->void($entry, $request->user()?->id);
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed.', 422, $e->errors());
        }

        return ApiResponse::success($voided, 'Entry voided and reversed.');
    }

    /** GET /cash-ledger/transactions  (unified paginated ledger: all cash movements) */
    public function transactions(Request $request, UnifiedCashFlowService $report)
    {
        $branchId = $this->branches->effectiveBranchId($request);

        $data = $report->transactions([
            'from'       => $request->input('from'),
            'to'         => $request->input('to'),
            'branch_id'  => $branchId,
            'direction'  => $request->input('direction'),    // in|out|all
            'kind'       => $request->input('kind'),         // all|module|received|sent|expense
            'category'   => $request->input('category'),     // module category filter
            'search'     => $request->input('search'),
            'page'       => (int) $request->input('page', 1),
            'per_page'   => (int) $request->input('per_page', 20),
        ]);

        return ApiResponse::success($data);
    }

    /** GET /cash-ledger/cash-flow  (unified summary) */
    public function cashFlow(Request $request, UnifiedCashFlowService $report)
    {
        $branchId = $this->branches->effectiveBranchId($request);

        $data = $report->build([
            'from'      => $request->input('from'),
            'to'        => $request->input('to'),
            'branch_id' => $branchId,
        ]);

        return ApiResponse::success($data);
    }

    /** GET /daybook  (day-by-day opening/in/out/closing over the unified ledger) */
    public function dayBook(Request $request, UnifiedCashFlowService $report)
    {
        $request->validate([
            'from'     => 'nullable|date_format:Y-m-d',
            'to'       => 'nullable|date_format:Y-m-d',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'order'    => 'nullable|in:asc,desc',
        ]);

        $branchId = $this->branches->effectiveBranchId($request);

        $data = $report->dayBookSummary([
            'from'      => $request->input('from'),
            'to'        => $request->input('to'),
            'branch_id' => $branchId,
            'page'      => (int) $request->input('page', 1),
            'per_page'  => (int) $request->input('per_page', 30),
            'order'     => $request->input('order', 'desc'),
        ]);

        return ApiResponse::success($data);
    }

    /** GET /daybook/day-details  (every cash movement for ONE day, labelled) */
    public function dayBookDetails(Request $request, UnifiedCashFlowService $report)
    {
        $request->validate([
            'date'      => 'required|date_format:Y-m-d',
            'direction' => 'nullable|in:in,out,all',
            'kind'      => 'nullable|in:all,module,received,sent,expense',
            'search'    => 'nullable|string',
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = $this->branches->effectiveBranchId($request);

        $data = $report->dayBookDetails([
            'date'      => $request->input('date'),
            'branch_id' => $branchId,
            'direction' => $request->input('direction', 'all'),
            'kind'      => $request->input('kind', 'all'),
            'search'    => $request->input('search'),
            'page'      => (int) $request->input('page', 1),
            'per_page'  => (int) $request->input('per_page', 50),
        ]);

        return ApiResponse::success($data);
    }
}
