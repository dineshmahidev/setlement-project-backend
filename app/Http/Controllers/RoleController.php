<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $query = Role::with('permissions');

        // Normal admin cannot see or assign Super Admin role
        if (! auth()->user()?->isSuperAdmin()) {
            $query->whereNotIn('name', [
                'super_admin',
                'superadmin',
                'Super Admin',
                'SUPER ADMIN',
                'master_admin',
                'masteradmin',
                'Master Admin',
                'MASTER ADMIN',
            ]);
        }

        $roles = $query->get();
        $permissions = Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $permissionIds = Permission::whereIn('name', $data['permissions'])->pluck('id');
        $role->permissions()->sync($permissionIds);

        return response()->json([
            'success' => true,
            'message' => 'Role permissions updated',
            'data' => $role->load('permissions'),
        ]);
    }
}
