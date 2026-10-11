<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'dashboard.view' => 'View Dashboard',
            'branches.manage' => 'Manage Branches',
            'users.manage' => 'Manage Users & Roles',
            'vendors.view' => 'View Vendors',
            'vendors.create' => 'Create Vendors',
            'vendors.edit' => 'Edit Vendors',
            'vendors.delete' => 'Delete Vendors',
            'vehicles.view' => 'View Vehicles',
            'vehicles.create' => 'Create Vehicles',
            'vehicles.edit' => 'Edit Vehicles',
            'vehicles.delete' => 'Delete Vehicles',
            'items.view' => 'View Items',
            'items.create' => 'Create Items',
            'items.edit' => 'Edit Items',
            'items.delete' => 'Delete Items',
            'settlement.view' => 'View Settlement Bills',
            'settlement.create' => 'Create Settlement Bills',
            'settlement.edit' => 'Edit Settlement Bills',
            'settlement.approve' => 'Approve Settlement Bills',
            'settlement.cancel' => 'Cancel Settlement Bills',
            'debitnote.view' => 'View Debit Notes',
            'debitnote.create' => 'Create Debit Notes',
            'debitnote.approve' => 'Approve Debit Notes',
            'inventory.view' => 'View Inventory',
            'inventory.stock_in' => 'Stock In',
            'inventory.stock_out' => 'Stock Out',
            'inventory.transfer' => 'Stock Transfer',
            'inventory.adjust' => 'Stock Adjustment',
            'vehicle.view' => 'View Vehicle Movements',
            'reports.view' => 'View Reports',
            'settings.manage' => 'Manage Settings & Field Config',
            'audit.view' => 'View Audit Logs',
        ];

        foreach ($permissions as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        $all = Permission::pluck('id', 'name');

        $rolePerms = [
            'SUPER ADMIN' => $all->keys()->all(),
            'ADMIN' => array_diff($all->keys()->all(), []),
            'BRANCH MANAGER' => [
                'dashboard.view', 'vendors.view', 'vendors.create', 'vendors.edit',
                'vehicles.view', 'items.view', 'settlement.view', 'settlement.create',
                'settlement.edit', 'settlement.approve', 'debitnote.view', 'debitnote.create',
                'inventory.view', 'inventory.stock_in', 'inventory.stock_out', 'inventory.transfer',
                'vehicle.view', 'reports.view',
            ],
            'BILLING STAFF' => [
                'dashboard.view', 'vendors.view', 'vehicles.view', 'items.view',
                'settlement.view', 'settlement.create', 'settlement.edit',
                'debitnote.view', 'debitnote.create', 'reports.view',
            ],
            'INVENTORY STAFF' => [
                'dashboard.view', 'items.view', 'vehicles.view', 'inventory.view',
                'inventory.stock_in', 'inventory.stock_out', 'inventory.transfer',
                'inventory.adjust', 'vehicle.view', 'reports.view',
            ],
            'ACCOUNTS STAFF' => [
                'dashboard.view', 'vendors.view', 'settlement.view', 'settlement.edit',
                'debitnote.view', 'debitnote.create', 'debitnote.approve', 'reports.view',
            ],
            'VIEWER' => [
                'dashboard.view', 'vendors.view', 'vehicles.view', 'items.view',
                'settlement.view', 'debitnote.view', 'inventory.view', 'vehicle.view',
                'reports.view',
            ],
        ];

        foreach ($rolePerms as $roleName => $perms) {
            $role = Role::updateOrCreate(['name' => $roleName], ['label' => str_replace(' ', '', ucwords(strtolower($roleName)))]);
            $ids = collect($perms)->map(fn ($p) => $all[$p])->filter()->all();
            $role->permissions()->sync($ids);
        }

        // Companies (each company owns its own branches)
        $this->call(CompanySeeder::class);

        $laxmiTraders = Branch::where('branch_code', 'BR-LT01')->firstOrFail();
        $smTraders = Branch::where('branch_code', 'BR-SM01')->firstOrFail();
        $shrieeFeeds = Branch::where('branch_code', 'BR-SF01')->firstOrFail();

        $allCompanies = \App\Models\Company::pluck('id')->all();

        // Admin user
        $admin = User::updateOrCreate(['email' => 'admin@laxmitraders.com'], [
            'name' => 'Laxmi Administrator',
            'employee_id' => 'EMP001',
            'mobile' => '9999999999',
            'password' => Hash::make('password'),
            'role_id' => Role::where('name', 'SUPER ADMIN')->first()->id,
            'default_branch_id' => $laxmiTraders->id,
            'status' => 'active',
        ]);
        $admin->companies()->sync($allCompanies);

        $bm = User::updateOrCreate(['email' => 'manager@laxmitraders.com'], [
            'name' => 'Branch Manager',
            'employee_id' => 'EMP002',
            'mobile' => '9888888888',
            'password' => Hash::make('password'),
            'role_id' => Role::where('name', 'BRANCH MANAGER')->first()->id,
            'default_branch_id' => $laxmiTraders->id,
            'status' => 'active',
        ]);
        $bm->branches()->sync([$laxmiTraders->id]);
        $bm->companies()->sync([$laxmiTraders->company_id]);

        $inv = User::updateOrCreate(['email' => 'inventory@laxmitraders.com'], [
            'name' => 'Inventory Staff',
            'employee_id' => 'EMP003',
            'mobile' => '9777777777',
            'password' => Hash::make('password'),
            'role_id' => Role::where('name', 'INVENTORY STAFF')->first()->id,
            'default_branch_id' => $laxmiTraders->id,
            'status' => 'active',
        ]);
        $inv->branches()->sync([$laxmiTraders->id, $smTraders->id, $shrieeFeeds->id]);
        $inv->companies()->sync($allCompanies);
    }
}
