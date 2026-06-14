<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Services\BranchContextService;
use App\Services\CashbookService;
use App\Services\LedgerService;
use App\Services\ReturnAnalyticsService;
use App\Services\StockMovementReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function ledger(Request $request, LedgerService $svc, BranchContextService $branches)
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(1, min(100, (int) $request->get('per_page', 15)));

        $from = $request->date('from');
        $to = $request->date('to');

        $data = $svc->getLedger([
            'party_type' => $request->query('party_type', 'customer'),
            'party_id' => $request->party_id,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage,
            'branch_id' => $branches->effectiveBranchId($request),
        ]);

        $label = ucfirst($data['party_type']) . ' ledger fetched successfully';
        return ApiResponse::success($this->withoutBranchData($data), $label);
    }

    public function cashbookDaily(Request $request, CashbookService $svc, BranchContextService $branches)
    {
        $filters = $request->all();
        $filters['branch_id'] = $branches->effectiveBranchId($request);
        $data = $svc->dailySummary($filters);

        return ApiResponse::success($this->withoutBranchData($data), 'Daily cashbook summary generated');
    }

    public function stockMovement(Request $request, StockMovementReportService $svc, BranchContextService $branches)
    {
        $filters = $request->all();
        $filters['branch_id'] = $branches->effectiveBranchId($request);
        $data = $svc->movementDetail($filters);

        return ApiResponse::success($this->withoutBranchData($data), 'Stock movement report generated');
    }

    public function profitLoss(Request $request, \App\Services\ProfitLossService $svc, BranchContextService $branches)
    {
        $data = $svc->summary([
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'branch_id' => $branches->effectiveBranchId($request),
        ]);

        return ApiResponse::success($this->withoutBranchData($data), 'Profit & loss report generated successfully');
    }

    public function returnAnalytics(Request $request, ReturnAnalyticsService $svc, BranchContextService $branches)
    {
        $data = $svc->analytics(
            $request->date('from'),
            $request->date('to'),
            $branches->effectiveBranchId($request),
            $request->integer('salesman_id') ?: null,
            $request->integer('customer_id') ?: null,
            max(1, (int) $request->get('page', 1)),
            max(1, (int) $request->get('per_page', 30))
        );

        return ApiResponse::success($this->withoutBranchData($data), 'Return analytics report generated successfully');
    }

    private function withoutBranchData(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach (['branch', 'branch_id', 'branch_name'] as $key) {
                unset($value[$key]);
            }

            foreach ($value as $key => $child) {
                $value[$key] = $this->withoutBranchData($child);
            }
        }

        return $value;
    }
}
