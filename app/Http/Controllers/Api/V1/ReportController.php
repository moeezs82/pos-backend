<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Services\LedgerService;
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
}
