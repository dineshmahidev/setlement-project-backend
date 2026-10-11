<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $allowedCompanyIds = $user->allowedCompanyIds(); // null = unrestricted
        $requested = $request->header('X-Company-Id') ?: $request->input('company_id');

        $company = null;

        if ($requested !== null && $requested !== '') {
            $company = Company::resolve((string) $requested);

            if (! $company) {
                abort(404, 'Company not found.');
            }

            if ($allowedCompanyIds !== null && ! in_array((int) $company->id, array_map('intval', $allowedCompanyIds), true)) {
                abort(403, 'You do not have access to this company.');
            }
        }

        // No explicit company requested: lock to the only one the user has.
        if (! $company && $allowedCompanyIds !== null && count($allowedCompanyIds) === 1) {
            $company = Company::find($allowedCompanyIds[0]);
        }

        $branchIds = $user->allowedBranchIds($company ? (int) $company->id : null);

        if ($company) {
            app()->instance('activeCompany', $company);
            $request->attributes->set('active_company_id', $company->id);
        }

        $request->merge([
            'allowed_branch_ids' => $branchIds,
            'allowed_company_ids' => $allowedCompanyIds,
            'active_company_id' => $company?->id,
        ]);

        return $next($request);
    }
}
