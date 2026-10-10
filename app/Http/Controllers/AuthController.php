<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
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
        ]);

        $user = User::with(['role.permissions', 'permissions', 'defaultBranch', 'branches'])
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

        // Collect all permissions
        $permissions = [];
        if ($user->role) {
            foreach ($user->role->permissions as $p) {
                $permissions[] = $p->name;
            }
        }
        foreach ($user->permissions as $p) {
            if ($p->pivot->granted) {
                $permissions[] = $p->name;
            }
        }
        $permissions = array_unique($permissions);

        AuditLog::logAction('logged_in', 'User', $user->id, $user->email, null, null, 'User logged in successfully');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'label' => $user->role->label,
                    ] : null,
                    'default_branch' => $user->defaultBranch,
                    'branches' => $user->branches,
                    'permissions' => $permissions,
                    'is_super_admin' => $user->hasRole(['super_admin', 'superadmin', 'Super Admin']),
                ],
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['role.permissions', 'permissions', 'defaultBranch', 'branches']);
        
        $permissions = [];
        if ($user->role) {
            foreach ($user->role->permissions as $p) {
                $permissions[] = $p->name;
            }
        }
        foreach ($user->permissions as $p) {
            if ($p->pivot->granted) {
                $permissions[] = $p->name;
            }
        }
        $permissions = array_unique($permissions);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'label' => $user->role->label,
                ] : null,
                'default_branch' => $user->defaultBranch,
                'branches' => $user->branches,
                'permissions' => $permissions,
                'is_super_admin' => $user->hasRole(['super_admin', 'superadmin', 'Super Admin']),
            ],
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
}
