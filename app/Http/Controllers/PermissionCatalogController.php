<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

class PermissionCatalogController extends Controller
{
    public function index()
    {
        return response()->json(Permission::orderBy('name')->get());
    }
}
