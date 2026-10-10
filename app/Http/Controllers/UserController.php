<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['role', 'defaultBranch', 'branches']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
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
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $userData = $request->only(['name', 'email', 'employee_id', 'mobile', 'role_id', 'default_branch_id', 'status']);
        $userData['password'] = Hash::make($request->password);
        $userData['status'] = strtolower($userData['status'] ?? 'active');

        $user = User::create($userData);

        if ($request->has('branch_ids')) {
            $user->branches()->sync($request->branch_ids);
        }

        AuditLog::logAction('created', 'User', $user->id, $user->email, null, $user->toArray(), 'User created');

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user->load(['role', 'defaultBranch', 'branches']),
        ], 201);
    }

    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'data' => $user->load(['role.permissions', 'defaultBranch', 'branches']),
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
            'default_branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive,ACTIVE,INACTIVE',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $old = $user->toArray();
        $userData = $request->only(['name', 'email', 'employee_id', 'mobile', 'role_id', 'default_branch_id', 'status']);
        if (isset($userData['status'])) {
            $userData['status'] = strtolower($userData['status']);
        }
        
        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $user->update($userData);

        if ($request->has('branch_ids')) {
            $user->branches()->sync($request->branch_ids);
        }

        AuditLog::logAction('updated', 'User', $user->id, $user->email, $old, $user->toArray(), 'User updated');

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->load(['role', 'defaultBranch', 'branches']),
        ]);
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot delete yourself.'], 422);
        }

        $old = $user->toArray();
        $user->delete();

        AuditLog::logAction('deleted', 'User', $user->id, $user->email, $old, null, 'User deleted');

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}
