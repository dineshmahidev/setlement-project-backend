<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOrSuperAdminMiddleware
{
    /**
     * Handle an incoming request.
     * Allows Super Admin and Admin roles (or users with users.view permission)
     * to access User Management.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin() || $user->isAdmin() || $user->canPermission('users.view')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized: User management is restricted to Admin and Super Admin.',
        ], 403);
    }
}
