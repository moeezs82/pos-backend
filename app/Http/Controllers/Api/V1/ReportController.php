<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Services\CashbookService;
use App\Services\LedgerService;
use App\Services\ReturnAnalyticsService;
use App\Services\StockMovementReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function ledger(Request $request, LedgerService $svc)
    {
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(100, (int)$request->get('per_page', 15)));

        // Optional date range (inclusive)
        $from = $request->date('from'); // Carbon|null
        $to   = $request->date('to');   // Carbon|null

        $partyType = $request->query('party_type', 'customer');
        $partyId = $request->party_id;

        // (Optional) validate – recommend a FormRequest in real usage
        $data = $svc->getLedger([
            'party_type' => $partyType,
            'party_id' => $partyId,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage
        ]);

        $label = ucfirst($data['party_type']) . ' ledger fetched successfully';
        return ApiResponse::success($data, $label);
    }

    public function cashbookDaily(Request $request, CashbookService $svc)
    {
        $data = $svc->dailySummary($request->all());
        return ApiResponse::success($data, 'Daily cashbook summary generated');
    }

    public function stockMovement(Request $request, StockMovementReportService $svc)
    {
        $data = $svc->movementDetail($request->all());
        return ApiResponse::success($data, 'Stock movement report generated');
    }

    public function profitLoss(Request $request, \App\Services\ProfitLossService $svc)
    {
        $from = $request->date('from'); // Carbon|null
        $to   = $request->date('to');   // Carbon|null

        $data = $svc->summary([
            'from'      => $from,
            'to'        => $to,
            'branch_id' => $request->integer('branch_id') ?: null,
        ]);

        return ApiResponse::success($data, 'Profit & loss report generated successfully');
    }

    public function returnAnalytics(Request $request, ReturnAnalyticsService $svc)
    {
        $from = $request->date('from'); // Carbon|null
        $to   = $request->date('to');   // Carbon|null

        $page    = max(1, (int)$request->get('page', 1));
        $perPage = max(1, (int)$request->get('per_page', 30));

        $data = $svc->analytics(
            $from,
            $to,
            $request->integer('branch_id') ?: null,
            $request->integer('salesman_id') ?: null,
            $request->integer('customer_id') ?: null,
            $page,
            $perPage
        );

        return ApiResponse::success($data, 'Return analytics report generated successfully');
    }
}
