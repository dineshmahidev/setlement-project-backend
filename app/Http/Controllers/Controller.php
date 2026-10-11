<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function activeCompany(): ?Company
    {
        if (app()->bound('activeCompany')) {
            return app('activeCompany');
        }

        $id = request('active_company_id');

        return $id ? Company::find($id) : null;
    }

    protected function activeCompanyId(): ?int
    {
        $company = $this->activeCompany();

        return $company ? (int) $company->id : null;
    }

    /**
     * Restrict a branch-scoped query to the branches the requester may see.
     */
    protected function scopeBranches($query, string $column = 'branch_id')
    {
        $ids = request('allowed_branch_ids');

        if (is_array($ids)) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    /**
     * Restrict a company-scoped query (masters: vendors, products, items...).
     */
    protected function scopeCompany($query, string $column = 'company_id')
    {
        $companyId = $this->activeCompanyId();

        if ($companyId) {
            $query->where($column, $companyId);
        } elseif (is_array($allowed = request('allowed_company_ids'))) {
            $query->whereIn($column, $allowed);
        }

        return $query;
    }

    protected function allowedBranchIds(): ?array
    {
        $user = request()->user();

        return $user ? $user->allowedBranchIds($this->activeCompanyId()) : null;
    }

    /**
     * 403 when the requester tries to write into a branch they cannot see.
     */
    protected function guardBranchAccess($branchId): void
    {
        $allowed = request('allowed_branch_ids');

        if (is_array($allowed) && $branchId !== null && ! in_array((int) $branchId, array_map('intval', $allowed), true)) {
            abort(403, 'You do not have access to this branch.');
        }
    }

    /**
     * 404 when a company-owned record belongs to another company.
     */
    protected function guardCompanyOwnership($model): void
    {
        $attributes = $model->getAttributes();

        if (! array_key_exists('company_id', $attributes)) {
            return;
        }

        $companyId = $attributes['company_id'];
        $active = $this->activeCompanyId();
        $allowed = request('allowed_company_ids');

        if ($active) {
            if ($companyId === null || (int) $companyId !== $active) {
                abort(404, 'Not found.');
            }

            return;
        }

        if (is_array($allowed) && $companyId !== null && ! in_array((int) $companyId, array_map('intval', $allowed), true)) {
            abort(404, 'Not found.');
        }
    }

    /**
     * 404 when a branch-owned record sits in a branch the requester cannot see.
     */
    protected function guardBranchOwnership($model): void
    {
        $attributes = $model->getAttributes();

        if (! array_key_exists('branch_id', $attributes)) {
            return;
        }

        $branchId = $attributes['branch_id'];
        $ids = request('allowed_branch_ids');

        if (is_array($ids) && $branchId !== null && ! in_array((int) $branchId, array_map('intval', $ids), true)) {
            abort(404, 'Not found.');
        }
    }

    protected function activeCompanyIdOrDefault($fallback = null): ?int
    {
        return $this->activeCompanyId() ?? $fallback;
    }

    protected function error(\Throwable $e, int $code = 400): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], $code);
    }
}
