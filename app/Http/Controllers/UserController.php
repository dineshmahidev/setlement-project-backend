<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['role', 'defaultBranch.company', 'branches.company', 'companies']);

        // Non-superadmin: HIDE Super Admin users completely
        if (! auth()->user()?->isSuperAdmin()) {
            $query->whereDoesntHave('role', function ($r) {
                $r->whereIn('name', [
                    'super_admin',
                    'superadmin',
                    'Super Admin',
                    'SUPER ADMIN',
                    'master_admin',
                    'masteradmin',
                    'Master Admin',
                    'MASTER ADMIN',
                ]);
            })->where('email', '!=', 'admin@laxmitraders.com');
        }

        // Check if explicit company filter was sent from frontend
        if ($request->filled('company_id')) {
            $cId = (int) $request->company_id;
            $query->where(function ($q) use ($cId) {
                $q->whereHas('companies', fn ($c) => $c->where('companies.id', $cId))
                    ->orWhereHas('branches', fn ($b) => $b->where('branches.company_id', $cId))
                    ->orWhereHas('defaultBranch', fn ($b) => $b->where('branches.company_id', $cId));
            });
        } elseif (! auth()->user()?->isSuperAdmin()) {
            // Non-superadmin: filter by active company
            $companyId = $this->activeCompanyId();
            if ($companyId) {
                $query->where(function ($q) use ($companyId) {
                    $q->whereHas('companies', fn ($c) => $c->where('companies.id', $companyId))
                        ->orWhereHas('branches', fn ($b) => $b->where('branches.company_id', $companyId))
                        ->orWhereHas('defaultBranch', fn ($b) => $b->where('branches.company_id', $companyId));
                });
            } elseif (is_array($allowed = request('allowed_company_ids'))) {
                $query->where(function ($q) use ($allowed) {
                    $q->whereHas('companies', fn ($c) => $c->whereIn('companies.id', $allowed))
                        ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.company_id', $allowed))
                        ->orWhereHas('defaultBranch', fn ($b) => $b->whereIn('branches.company_id', $allowed));
                });
            }
        }

        if ($request->filled('branch_id')) {
            $bId = (int) $request->branch_id;
            $query->where(function ($q) use ($bId) {
                $q->where('default_branch_id', $bId)
                    ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $bId));
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $isSuper = auth()->user()?->isSuperAdmin();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'employee_id' => 'nullable|string|unique:users,employee_id',
            'mobile' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'company_ids' => 'nullable|array',
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
        ]);

        // Prevent normal admin from assigning Super Admin role
        if (! $isSuper) {
            $role = Role::find($request->role_id);
            if ($role && in_array(strtolower(trim($role->name)), ['super_admin', 'superadmin', 'master_admin', 'masteradmin'])) {
                return response()->json(['success' => false, 'message' => 'Unauthorized: Cannot assign Super Admin role.'], 403);
            }
        }

        $branchIds = (array) ($request->branch_ids ?? []);
        $defaultBranchId = $request->default_branch_id ? (int) $request->default_branch_id : null;

        // Super Admin can assign ALL branches or ALL companies to any user
        if ($isSuper && ($request->boolean('all_branches') || in_array('all', $branchIds, true))) {
            $branchIds = Branch::pluck('id')->all();
        }
        if ($isSuper && ($request->boolean('all_companies') || in_array('all', (array)$request->company_ids, true))) {
            $request->merge(['company_ids' => \App\Models\Company::pluck('id')->all()]);
        }

        // Normal admin may only assign companies/godowns inside their own scope
        if (! $isSuper) {
            $this->guardAssignableScope($request, $branchIds, $defaultBranchId);
        }

        // Ensure default branch is in accessible branches list
        if ($defaultBranchId && ! in_array($defaultBranchId, array_map('intval', $branchIds), true)) {
            $branchIds[] = $defaultBranchId;
        }

        // If no default branch is provided but branches are chosen, use first branch
        if (! $defaultBranchId && ! empty($branchIds)) {
            $defaultBranchId = (int) $branchIds[0];
        }

        $finalCompanyIds = $this->companyIdsFor($request, new User(), $branchIds, $defaultBranchId);
        if (! $isSuper && empty($finalCompanyIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one company for this user.',
            ], 422);
        }

        $userData = $request->only(['name', 'email', 'employee_id', 'mobile', 'role_id', 'status']);
        $userData['default_branch_id'] = $defaultBranchId;
        $userData['password'] = Hash::make($request->password);
        $userData['status'] = strtolower($userData['status'] ?? 'active');

        $user = User::create($userData);

        // Sync branches
        if (! empty($branchIds)) {
            $user->branches()->sync(array_values(array_unique(array_map('intval', $branchIds))));
        }

        // Sync companies
        $user->companies()->sync($finalCompanyIds);

        AuditLog::logAction('created', 'User', $user->id, $user->email, null, $user->toArray(), 'User created');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user->load(['role', 'defaultBranch.company', 'branches.company', 'companies']),
        ], 201);
    }

    public function show(User $user)
    {
        $this->guardUserVisibility($user);

        return response()->json([
            'success' => true,
            'data' => $user->load(['role.permissions', 'defaultBranch.company', 'branches.company', 'companies']),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $isSuper = auth()->user()?->isSuperAdmin();

        // Non-superadmin cannot touch a Super Admin user
        if (! $isSuper && $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: Cannot modify a Super Admin user.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'employee_id' => ['nullable', 'string', Rule::unique('users')->ignore($user->id)],
            'mobile' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'company_ids' => 'nullable|array',
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
        ]);

        // Prevent normal admin from assigning Super Admin role
        if (! $isSuper) {
            $role = Role::find($request->role_id);
            if ($role && in_array(strtolower(trim($role->name)), ['super_admin', 'superadmin', 'master_admin', 'masteradmin'])) {
                return response()->json(['success' => false, 'message' => 'Unauthorized: Cannot assign Super Admin role.'], 403);
            }
        }

        // Normal admin can only modify users inside their company scope
        $this->guardUserVisibility($user);

        $old = $user->toArray();

        $hasBranchPayload = $request->exists('branch_ids');
        $branchIds = $hasBranchPayload
            ? (array) $request->input('branch_ids', [])
            : $user->branches()->pluck('branches.id')->all();

        $hasDefaultPayload = $request->exists('default_branch_id');
        $rawDefaultBranchId = $request->input('default_branch_id');
        $defaultBranchId = $hasDefaultPayload
            ? (($rawDefaultBranchId === null || $rawDefaultBranchId === '') ? null : (int) $rawDefaultBranchId)
            : $user->default_branch_id;

        // Super Admin can assign ALL branches or ALL companies to any user
        if ($isSuper && ($request->boolean('all_branches') || in_array('all', $branchIds, true))) {
            $branchIds = Branch::pluck('id')->all();
        }
        if ($isSuper && ($request->boolean('all_companies') || in_array('all', (array)$request->company_ids, true))) {
            $request->merge(['company_ids' => \App\Models\Company::pluck('id')->all()]);
        }

        // Normal admin may only assign companies/godowns inside their own scope
        if (! $isSuper) {
            $this->guardAssignableScope($request, $branchIds, $defaultBranchId);
        }

        // If branch list changed without an explicit default, keep the old default only when still allowed.
        if ($hasBranchPayload && ! $hasDefaultPayload) {
            $normalizedBranchIds = array_map('intval', $branchIds);
            $defaultBranchId = $user->default_branch_id && in_array((int) $user->default_branch_id, $normalizedBranchIds, true)
                ? (int) $user->default_branch_id
                : (empty($normalizedBranchIds) ? null : (int) $normalizedBranchIds[0]);
        }

        if ($defaultBranchId && ! in_array($defaultBranchId, array_map('intval', $branchIds), true)) {
            $branchIds[] = $defaultBranchId;
        }

        if (! $defaultBranchId && ! empty($branchIds) && ! $hasDefaultPayload) {
            $defaultBranchId = (int) $branchIds[0];
        }

        $userData = $request->only(['name', 'email', 'employee_id', 'mobile', 'role_id', 'status']);
        $userData['default_branch_id'] = $defaultBranchId;
        if (isset($userData['status'])) {
            $userData['status'] = strtolower($userData['status']);
        }
        
        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $user->update($userData);

        // Sync branches
        $user->branches()->sync(array_values(array_unique(array_map('intval', $branchIds))));

        // Sync companies
        $finalCompanyIds = $this->companyIdsFor($request, $user, $branchIds, $defaultBranchId);
        $user->companies()->sync($finalCompanyIds);

        AuditLog::logAction('updated', 'User', $user->id, $user->email, $old, $user->toArray(), 'User updated');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->load(['role', 'defaultBranch.company', 'branches.company', 'companies']),
        ]);
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot deactivate yourself.'], 422);
        }

        // Non-superadmin cannot deactivate a Super Admin user
        if (! auth()->user()?->isSuperAdmin() && $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: Cannot deactivate a Super Admin user.'], 403);
        }

        $this->guardUserVisibility($user);

        $old = $user->toArray();
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        AuditLog::logAction('deactivated', 'User', $user->id, $user->email, $old, $user->toArray(), 'User deactivated');

        return response()->json([
            'success' => true,
            'message' => 'User deactivated successfully',
        ]);
    }

    /**
     * Company ids a user should belong to after a create/update.
     */
    private function companyIdsFor(Request $request, User $user, array $branchIds = [], ?int $defaultBranchId = null): array
    {
        $companyIds = [];

        if ($request->exists('company_ids')) {
            $companyIds = array_merge($companyIds, array_map('intval', (array) $request->input('company_ids', [])));
        }

        if ($request->filled('company_id')) {
            $companyIds[] = (int) $request->company_id;
        }

        // Branch-derived companies
        if (! empty($branchIds)) {
            $fromBranches = Branch::whereIn('id', $branchIds)
                ->pluck('company_id')
                ->filter()
                ->all();
            $companyIds = array_merge($companyIds, $fromBranches);
        } elseif ($defaultBranchId) {
            $fromDefault = Branch::whereKey($defaultBranchId)->value('company_id');
            if ($fromDefault) {
                $companyIds[] = (int) $fromDefault;
            }
        }

        $hasExplicitCompanyPayload = $request->exists('company_ids') || $request->filled('company_id');

        // Active company fallback only when the caller did not send explicit company data
        if (empty($companyIds) && ! $hasExplicitCompanyPayload && $this->activeCompanyId()) {
            $companyIds[] = (int) $this->activeCompanyId();
        }

        if (empty($companyIds) && ! $hasExplicitCompanyPayload && $user->defaultBranch?->company_id) {
            $companyIds[] = (int) $user->defaultBranch->company_id;
        }

        return array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    }

    /**
     * Normal admins may only assign companies and godowns inside their own scope.
     */
    private function guardAssignableScope(Request $request, array $branchIds, ?int $defaultBranchId): void
    {
        $actor = $request->user();

        if (! $actor || $actor->isSuperAdmin()) {
            return;
        }

        $allowedCompanies = array_map('intval', $actor->allowedCompanyIds() ?? []);
        $allowedBranches = array_map('intval', $actor->allowedBranchIds() ?? []);

        $requestedCompanyIds = array_map('intval', (array) $request->input('company_ids', []));
        if ($request->filled('company_id')) {
            $requestedCompanyIds[] = (int) $request->company_id;
        }

        $normalizedBranchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));

        if (! empty($normalizedBranchIds)) {
            $requestedCompanyIds = array_merge(
                $requestedCompanyIds,
                Branch::whereIn('id', $normalizedBranchIds)->pluck('company_id')->filter()->all()
            );
        }

        $requestedCompanyIds = array_values(array_unique(array_filter($requestedCompanyIds)));

        if (! empty(array_diff($requestedCompanyIds, $allowedCompanies))) {
            abort(403, 'You can only assign companies you have access to.');
        }

        if (! empty(array_diff($normalizedBranchIds, $allowedBranches))) {
            abort(403, 'You can only assign godowns you have access to.');
        }

        if ($defaultBranchId !== null && ! in_array($defaultBranchId, $allowedBranches, true)) {
            abort(403, 'You cannot assign this default godown.');
        }
    }

    private function guardUserVisibility(User $user): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            return;
        }

        // Normal admin CANNOT see, touch, or interact with Super Admin users
        if ($user->isSuperAdmin()) {
            abort(404, 'User not found.');
        }

        $companyId = $this->activeCompanyId();
        $allowed = request('allowed_company_ids');

        $userCompanyIds = $user->companies()->pluck('companies.id')
            ->merge($user->branches()->pluck('branches.company_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($userCompanyIds)) {
            return;
        }

        if ($companyId && ! in_array($companyId, array_map('intval', $userCompanyIds), true)) {
            abort(404, 'Not found.');
        }

        if (! $companyId && is_array($allowed)) {
            $overlap = array_intersect(array_map('intval', $allowed), array_map('intval', $userCompanyIds));
            if (empty($overlap)) {
                abort(404, 'Not found.');
            }
        }
    }
}
