<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\PermissionAuditLog;
use Illuminate\Http\Request;

class PermissionAuditLogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'target_type' => ['nullable', 'string', 'max:255'],
            'target_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = PermissionAuditLog::query()
            ->with('actor:id,name,email')
            ->when($data['branch_id'] ?? null, fn ($q, $value) => $q->where('branch_id', $value))
            ->when($data['actor_id'] ?? null, fn ($q, $value) => $q->where('actor_id', $value))
            ->when($data['action'] ?? null, fn ($q, $value) => $q->where('action', $value))
            ->when($data['target_type'] ?? null, fn ($q, $value) => $q->where('target_type', $value))
            ->when($data['target_id'] ?? null, fn ($q, $value) => $q->where('target_id', $value))
            ->when($data['from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))
            ->latest('id');

        return ApiResponse::success($query->paginate((int) ($data['per_page'] ?? 50)));
    }
}
