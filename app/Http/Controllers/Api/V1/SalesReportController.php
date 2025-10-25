<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    public function __construct(private SalesReportService $svc) {}

    private function dt(?string $v): ?Carbon
    {
        return $v ? Carbon::parse($v) : null;
    }

    public function dailySummary(Request $r)
    {
        $res = $this->svc->dailySummaryByDay(
            $this->dt($r->query('from')),
            $this->dt($r->query('to')),
            $r->integer('branch_id'),
            $r->integer('salesman_id'),
            $r->integer('customer_id')
        );
        return response()->json(['data' => $res]);
    }

    public function topBottom(Request $r)
    {
        $res = $this->svc->topBottomProducts(
            $r->filled('from') ? Carbon::parse($r->query('from')) : null,
            $r->filled('to')   ? Carbon::parse($r->query('to'))   : null,
            $r->integer('branch_id'),
            $r->integer('salesman_id'),
            $r->integer('customer_id'),
            $r->integer('category_id'),
            $r->integer('vendor_id'),
            $r->query('sort_by', 'revenue'),
            $r->query('direction', 'desc'),
            (int)$r->query('page', 1),
            (int)$r->query('per_page', 20)
        );
        return response()->json(['data' => $res]);
    }
}
