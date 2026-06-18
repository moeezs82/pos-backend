<?php

namespace App\Http\Middleware;

use App\Http\Response\ApiResponse;
use App\Services\BranchContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchContext
{
    public function __construct(private BranchContextService $branches) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /*
             * Important:
             * Read requested branch BEFORE merging effective branch.
             * Otherwise switch-branch requests get overwritten by current user.branch_id.
             */
            $requestedBranch = $this->branches->requestedBranchId($request);

            if (!$this->branches->isMasterAdmin($request->user()) && $requestedBranch) {
                $this->branches->assertCanAccessBranch($request, $requestedBranch);
            }

            /*
             * Do not inject current branch into endpoints where the caller is
             * explicitly choosing a branch_id that has nothing to do with
             * which branch they're currently working in:
             *   - switch-branch: master admin picking which branch to work in
             *   - printer-config save/test: master admin choosing which branch
             *     (or the global default, branch_id = null) a printer setting
             *     applies to
             * Without this, mergeEffectiveBranchIntoRequest() below would
             * silently overwrite their actual selection with their own
             * current branch_id before the controller ever sees it.
             */
            $branchChoiceIsExplicit = $request->is('api/v1/switch-branch')
                || $request->routeIs('switch-branch')
                || $request->is('api/v1/printer-config/save')
                || $request->is('api/v1/printer-config/test')
                || $request->routeIs('printer-config.save')
                || $request->routeIs('printer-config.test');

            if (!$branchChoiceIsExplicit) {
                $this->branches->mergeEffectiveBranchIntoRequest($request);
            } else {
                $request->attributes->set('effective_branch_id', $this->branches->effectiveBranchId($request));
                $request->attributes->set('is_master_admin', $this->branches->isMasterAdmin($request->user()));
            }
        } catch (ValidationException $e) {
            return ApiResponse::error($e->errors()['branch_id'][0] ?? 'Invalid branch context.', 403);
        }

        return $next($request);
    }
}