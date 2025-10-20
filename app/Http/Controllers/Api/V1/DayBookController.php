<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Services\DayBookService;
use Illuminate\Http\Request;

class DayBookController extends Controller
{
    public function index(Request $request, DayBookService $svc)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'from'      => 'nullable|date_format:Y-m-d',
            'to'        => 'nullable|date_format:Y-m-d',
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:200',
            'order'     => 'nullable|in:asc,desc', // default desc
        ]);

        return ApiResponse::success(
            $svc->summary(
                $data['branch_id'] ?? null,
                $data['from'] ?? null,
                $data['to'] ?? null,
                (int)($data['page'] ?? 1),
                (int)($data['per_page'] ?? 30),
                $data['order'] ?? 'desc',
            )
        );
    }

    public function dayDetails(Request $request, DayBookService $svc)
    {
        $data = $request->validate([
            'date'           => 'required|date_format:Y-m-d',
            'branch_id'      => 'nullable|integer|exists:branches,id',
            'page'           => 'nullable|integer|min:1',
            'per_page'       => 'nullable|integer|min:1|max:500',
            'sort'           => 'nullable|in:created_at,in,out,net,reference_type',
            'order'          => 'nullable|in:asc,desc',
            'reference_type' => 'nullable|string', // e.g., "App\\Models\\Sale"
            'search'         => 'nullable|string',
            'include_lines'  => 'nullable|boolean',
        ]);

        return ApiResponse::success(
            $svc->details(
                $data['branch_id'] ?? null,
                $data['date'],
                (int)($data['page'] ?? 1),
                (int)($data['per_page'] ?? 100),
                $data['sort'] ?? 'created_at',
                $data['order'] ?? 'asc',
                $data['reference_type'] ?? null,
                $data['search'] ?? null,
                (bool)($data['include_lines'] ?? true),
            )
        );
    }
}
