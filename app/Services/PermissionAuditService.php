<?php

namespace App\Services;

use App\Models\PermissionAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class PermissionAuditService
{
    public function record(
        Request $request,
        string $action,
        Model $target,
        array $before = [],
        array $after = []
    ): void {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();

        PermissionAuditLog::query()->create([
            'actor_id' => $actor?->id,
            'branch_id' => $request->attributes->get('effective_branch_id')
                ?? $actor?->branch_id
                ?? $target->branch_id
                ?? null,
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'access_token_id' => $token?->getKey(),
            'created_at' => now(),
        ]);
    }
}
