<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'company_id' => 'nullable',
        ]);

        $user = User::with(['role.permissions', 'permissions', 'defaultBranch', 'branches.company', 'companies'])
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated. Please contact the administrator.',
            ], 403);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        AuditLog::logAction('logged_in', 'User', $user->id, $user->email, null, null, 'User logged in successfully');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user, $request->input('company_id')),
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->loadMissing(['role.permissions', 'permissions', 'defaultBranch', 'branches.company', 'companies']);

        $requestedCompanyId = $this->activeCompanyId()
            ?? $request->header('X-Company-Id')
            ?? $request->input('company_id')
            ?? $request->attributes->get('active_company_id');

        return response()->json([
            'success' => true,
            'data' => $this->userPayload($user, $requestedCompanyId),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->currentAccessToken()->delete();
            AuditLog::logAction('logged_out', 'User', $user->id, $user->email);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    private function userPayload(User $user, $requestedCompanyId = null): array
    {
        $permissions = $this->collectPermissions($user);

        $allowedCompanyIds = $user->allowedCompanyIds(); // null = all
        $companies = $user->companies
            ->sortBy('sort_order')
            ->filter(fn ($c) => $allowedCompanyIds === null || in_array($c->id, $allowedCompanyIds, true))
            ->values();

        if ($allowedCompanyIds === null) {
            $companies = \App\Models\Company::active()
                ->orderBy('sort_order')
                ->orderBy('company_name')
                ->get();
        }

        $activeCompany = $this->resolveActiveCompany($user, $companies, $requestedCompanyId);

        if ($user->isSuperAdmin()) {
            $branches = $activeCompany
                ? \App\Models\Branch::query()->where('company_id', $activeCompany->id)->get()
                : \App\Models\Branch::query()->get();
        } else {
            $branches = $user->branches
                ->filter(fn ($b) => ! $activeCompany || (int) $b->company_id === (int) $activeCompany->id)
                ->values();

            if ($branches->isEmpty() && $user->default_branch_id) {
                $default = $user->defaultBranch;
                if (! $activeCompany || ($default && (int) $default->company_id === (int) $activeCompany->id)) {
                    $branches = collect([$default])->filter();
                }
            }
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'mobile' => $user->mobile,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'label' => $user->role->label,
            ] : null,
            'default_branch' => $user->defaultBranch,
            'companies' => $companies->map(fn (Company $c) => $this->companyPayload($c))->values(),
            'active_company' => $activeCompany ? $this->companyPayload($activeCompany) : null,
            'branches' => $branches->values(),
            'allowed_company_ids' => $allowedCompanyIds,
            'allowed_branch_ids' => $user->allowedBranchIds($activeCompany ? (int) $activeCompany->id : null),
            'permissions' => $permissions,
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }

    private function resolveActiveCompany(User $user, $companies, $requestedCompanyId = null): ?Company
    {
        if ($requestedCompanyId !== null && $requestedCompanyId !== '') {
            $found = $companies->first(fn ($c) => (int) $c->id === (int) $requestedCompanyId || $c->slug === (string) $requestedCompanyId || $c->code === (string) $requestedCompanyId);
            if ($found) {
                return $found;
            }
        }

        return $companies->first();
    }

    private function companyPayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'slug' => $company->slug,
            'company_name' => $company->company_name,
            'short_name' => $company->short_name,
            'gstin' => $company->gstin,
            'city' => $company->city,
            'tagline' => $company->tagline,
            'sub_tagline' => $company->sub_tagline,
            'logo_path' => $company->logo_path,
            'logo_transparent_path' => $company->logo_transparent_path,
            'banner_path' => $company->banner_path,
            'signature_path' => $company->signature_path,
            'status' => $company->status,
            'panel_url' => '/panel/' . $company->slug,
            'branches' => $company->branches->map(fn ($b) => [
                'id' => $b->id,
                'company_id' => $b->company_id,
                'branch_name' => $b->branch_name,
                'branch_code' => $b->branch_code,
                'city' => $b->city,
            ])->values(),
            'branches_count' => $company->branches->count(),
        ];
    }

    private function collectPermissions(User $user): array
    {
        $permissions = [];
        foreach ($user->role->permissions ?? [] as $p) {
            $permissions[] = $p->name;
        }
        foreach ($user->permissions as $p) {
            if ($p->pivot->granted) {
                $permissions[] = $p->name;
            }
        }

        return array_values(array_unique($permissions));
    }
}
