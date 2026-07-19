<?php

namespace App\Http\Middleware;

use App\Http\Response\ApiResponse;
use App\Services\BranchContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * Server-side, deny-by-default guard for global Master-Admin-only operations
 * (Chart of Accounts management, branch payment-method mappings).
 *
 * Uses the authoritative BranchContextService::isMasterAdmin() check — never a
 * role label supplied by the client, and never merely the `manage-accounts`
 * permission (which could be stale on an old branch role).
 */
class EnsureMasterAdmin
{
    public function __construct(private BranchContextService $branches) {}

    public function handle(Request $request, Closure $next)
    {
        if (!$this->branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only Master Admin can manage accounts.', 403);
        }

        return $next($request);
    }
}
