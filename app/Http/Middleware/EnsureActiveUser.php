<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->is_active) {
            return response()->json([
                'success' => false,
                'code' => 'ACCOUNT_INACTIVE',
                'message' => 'This user account is inactive.',
                'errors' => [],
            ], 403);
        }

        return $next($request);
    }
}
