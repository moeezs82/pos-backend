<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportFilterRequest;
use App\Http\Response\ApiResponse;
use App\Services\BranchContextService;
use App\Services\Reports\EnterpriseReportService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\JsonResponse;
use Throwable;

class EnterpriseReportController extends Controller
{
    public function __construct(
        private EnterpriseReportService $reports,
        private ReportExportService $exports,
    ) {}

    public function catalog(): JsonResponse
    {
        return ApiResponse::success([
            'groups' => $this->reports->catalog(),
            'filters' => [
                'from' => 'YYYY-MM-DD HH:MM:SS',
                'to' => 'YYYY-MM-DD HH:MM:SS',
                'customer_id' => 'optional customer id',
                'vendor_id' => 'optional vendor id',
                'product_id' => 'optional product id',
                'category_id' => 'optional category id',
                'brand_id' => 'optional brand id',
                'salesman_id' => 'optional cashier/salesman user id',
                'delivery_boy_id' => 'optional delivery user id',
                'status' => 'optional status',
                'method' => 'optional payment method',
                'search' => 'optional text search',
                'page' => 'default 1',
                'per_page' => 'default 50, max 500 (max 5000 when exporting)',
            ],
            'exports' => ['xlsx', 'pdf'],
        ], 'Enterprise report catalog fetched successfully');
    }

    public function show(string $report, ReportFilterRequest $request, BranchContextService $branches): JsonResponse
    {
        $payload = $this->branchScopedPayload($request, $branches);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $data = $this->reports->run($report, $payload, false);
        return ApiResponse::success($data, $data['title'] . ' generated successfully');
    }

    public function export(string $report, ReportFilterRequest $request, BranchContextService $branches)
    {
        $payload = $this->branchScopedPayload($request, $branches);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $format = strtolower($payload['format'] ?? 'xlsx');
        $orientation = strtolower($payload['orientation'] ?? 'landscape');

        try {
            $data = $this->reports->run($report, $payload, true);
            return $this->exports->download($data, $format, $orientation);
        } catch (Throwable $e) {
            report($e);
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    private function branchScopedPayload(ReportFilterRequest $request, BranchContextService $branches): array|JsonResponse
    {
        try {
            $branchId = $branches->requireBranchId($request);
        } catch (\Throwable) {
            return ApiResponse::error('Please select a working branch before opening reports.', 422);
        }

        $payload = $request->validated();
        $payload['branch_id'] = $branchId;

        return $payload;
    }
}
