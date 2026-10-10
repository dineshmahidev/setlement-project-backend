<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canPermission($permission)) {
            return response()->json(['message' => 'Forbidden: missing permission '.$permission], 403);
        }

        return $next($request);
    }
}
