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
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'employee_id' => 'nullable|string|unique:users,employee_id',
            'mobile' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $branchIds = (array) ($request->branch_ids ?? []);
        $defaultBranchId = $request->default_branch_id ? (int) $request->default_branch_id : null;

        // Ensure default branch is in accessible branches list
        if ($defaultBranchId && ! in_array($defaultBranchId, array_map('intval', $branchIds), true)) {
            $branchIds[] = $defaultBranchId;
        }

        // If no default branch is provided but branches are chosen, use first branch
        if (! $defaultBranchId && ! empty($branchIds)) {
            $defaultBranchId = (int) $branchIds[0];
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
        $finalCompanyIds = $this->companyIdsFor($request, $user);
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
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'employee_id' => ['nullable', 'string', Rule::unique('users')->ignore($user->id)],
            'mobile' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $old = $user->toArray();
        $branchIds = (array) ($request->branch_ids ?? $user->branches()->pluck('branches.id')->all());
        $defaultBranchId = $request->filled('default_branch_id') ? (int) $request->default_branch_id : $user->default_branch_id;

        if ($defaultBranchId && ! in_array($defaultBranchId, array_map('intval', $branchIds), true)) {
            $branchIds[] = $defaultBranchId;
        }

        if (! $defaultBranchId && ! empty($branchIds)) {
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
        $finalCompanyIds = $this->companyIdsFor($request, $user);
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
            return response()->json(['success' => false, 'message' => 'You cannot delete yourself.'], 422);
        }

        $this->guardUserVisibility($user);

        $old = $user->toArray();
        $user->delete();

        AuditLog::logAction('deleted', 'User', $user->id, $user->email, $old, null, 'User deleted');

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Company ids a user should belong to after a create/update.
     */
    private function companyIdsFor(Request $request, User $user): array
    {
        $companyIds = [];

        if ($request->filled('company_ids')) {
            $companyIds = array_merge($companyIds, array_map('intval', (array) $request->company_ids));
        }

        if ($request->filled('company_id')) {
            $companyIds[] = (int) $request->company_id;
        }

        // Branch-derived companies
        $branchIds = (array) ($request->branch_ids ?? []);
        if ($request->filled('default_branch_id')) {
            $branchIds[] = (int) $request->default_branch_id;
        }
        if (empty($branchIds) && $user->exists) {
            $branchIds = $user->branches()->pluck('branches.id')->all();
        }

        if (! empty($branchIds)) {
            $fromBranches = Branch::whereIn('id', $branchIds)
                ->pluck('company_id')
                ->filter()
                ->all();
            $companyIds = array_merge($companyIds, $fromBranches);
        }

        // Active company fallback
        if (empty($companyIds) && $this->activeCompanyId()) {
            $companyIds[] = (int) $this->activeCompanyId();
        }

        if (empty($companyIds) && $user->defaultBranch?->company_id) {
            $companyIds[] = (int) $user->defaultBranch->company_id;
        }

        return array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    }

    private function guardUserVisibility(User $user): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            return;
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
