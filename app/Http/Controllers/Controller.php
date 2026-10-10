<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function scopeBranches($query)
    {
        $ids = request('allowed_branch_ids');
        if (is_array($ids) && request()->user() && ! request()->user()->isSuperAdmin()) {
            $query->whereIn('branch_id', $ids);
        }
        return $query;
    }

    protected function allowedBranchIds(): ?array
    {
        $user = request()->user();
        return $user ? $user->allowedBranchIds() : null;
    }

    protected function error(\Throwable $e, int $code = 400): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], $code);
    }
}
