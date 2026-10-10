<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $request->merge(['allowed_branch_ids' => $user->allowedBranchIds()]);
        }

        return $next($request);
    }
}
