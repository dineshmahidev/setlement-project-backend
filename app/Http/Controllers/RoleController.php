<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }
}
