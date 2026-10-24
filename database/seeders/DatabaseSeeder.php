<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('audit_logs')->truncate();
        DB::table('role_permissions')->truncate();
        DB::table('user_branches')->truncate();
        DB::table('user_permissions')->truncate();
        DB::table('user_companies')->truncate();
        User::truncate();
        Role::truncate();
        Permission::truncate();
        Branch::truncate();
        Company::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 1. Roles
        $roles = [
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            'branch_manager' => 'Branch Manager',
            'staff' => 'Staff / Operator',
            'inventory_staff' => 'Inventory Staff',
        ];
        $roleIds = [];
        foreach ($roles as $name => $label) {
            $roleIds[$name] = Role::create(['name' => $name, 'label' => $label])->id;
        }

        // 2. Permissions
        $modules = [
            'dashboard' => ['view'],
            'companies' => ['view', 'manage'],
            'branches' => ['view', 'create', 'edit', 'delete', 'manage'],
            'vendors' => ['view', 'create', 'edit', 'delete', 'manage'],
            'products' => ['view', 'create', 'edit', 'delete', 'manage'],
            'invoices' => ['view', 'create', 'edit', 'cancel', 'manage'],
            'settlements' => ['view', 'create', 'edit', 'approve', 'cancel', 'pdf'],
            'debit_notes' => ['view', 'create', 'edit', 'approve', 'cancel', 'pdf'],
            'vendor_ledger' => ['view', 'adjust', 'export'],
            'inventory' => ['view', 'stock_in', 'stock_out', 'transfer', 'adjust'],
            'reports' => ['view', 'export'],
            'users' => ['view', 'create', 'edit', 'delete', 'manage'],
            'settings' => ['view', 'edit', 'manage'],
            'audit' => ['view'],
        ];

        $allPermIds = [];
        $permByName = [];
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $p = Permission::create([
                    'name' => "{$module}.{$action}",
                    'label' => ucwords(str_replace('_', ' ', "{$action} {$module}")),
                    'module' => $module,
                ]);
                $allPermIds[] = $p->id;
                $permByName[$p->name] = $p->id;
            }
        }

        $sync = function (string $role, array $ids) use ($roleIds) {
            Role::find($roleIds[$role])->permissions()->sync($ids);
        };

        $sync('super_admin', $allPermIds);
        $sync('admin', $allPermIds);

        $sync('branch_manager', Permission::whereIn('module', [
            'companies', 'branches', 'vendors', 'products', 'invoices',
            'settlements', 'debit_notes', 'vendor_ledger', 'inventory', 'reports',
        ])->pluck('id')->all());

        $sync('inventory_staff', collect([
            'dashboard.view',
            'branches.view', 'vendors.view', 'products.view', 'invoices.view',
            'settlements.view', 'settlements.pdf', 'debit_notes.view', 'debit_notes.pdf',
            'vendor_ledger.view', 'reports.view',
            'inventory.view', 'inventory.stock_in', 'inventory.stock_out',
            'inventory.transfer', 'inventory.adjust',
        ])->map(fn ($n) => $permByName[$n])->filter()->all());

        $sync('staff', collect([
            'vendors.view', 'vendors.create', 'products.view',
            'invoices.view', 'invoices.create', 'settlements.view',
            'settlements.create', 'settlements.pdf', 'debit_notes.view',
            'debit_notes.pdf', 'vendor_ledger.view', 'reports.view',
        ])->map(fn ($n) => $permByName[$n])->filter()->all());

        // 3. Companies + their godowns (branches)
        $this->call(CompanySeeder::class);

        $laxmi = Company::where('code', 'laxmi')->firstOrFail();
        $allCompanyIds = Company::pluck('id')->all();
        $allBranchIds = Branch::pluck('id')->all();
        $laxmiBranchIds = Branch::where('company_id', $laxmi->id)->pluck('id')->all();
        $defaultBranchId = Branch::where('branch_code', 'BR-LT01')->value('id');

        // 4. Users
        $makeUser = function (array $data, int $roleId, array $branchIds, array $companyIds) {
            $user = User::create($data + [
                'password' => Hash::make('password'),
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $user->branches()->sync($branchIds);
            $user->companies()->sync($companyIds);

            return $user;
        };

        $makeUser([
            'name' => 'Laxmi Administrator',
            'email' => 'admin@laxmitraders.com',
            'employee_id' => 'EMP-001',
            'mobile' => '9876543210',
            'default_branch_id' => $defaultBranchId,
        ], $roleIds['super_admin'], $allBranchIds, $allCompanyIds);

        $makeUser([
            'name' => 'Branch Manager',
            'email' => 'manager@laxmitraders.com',
            'employee_id' => 'EMP-002',
            'mobile' => '9876543211',
            'default_branch_id' => $defaultBranchId,
        ], $roleIds['branch_manager'], $laxmiBranchIds, [$laxmi->id]);

        $makeUser([
            'name' => 'Inventory Staff',
            'email' => 'inventory@laxmitraders.com',
            'employee_id' => 'EMP-003',
            'mobile' => '9876543212',
            'default_branch_id' => $defaultBranchId,
        ], $roleIds['inventory_staff'], $allBranchIds, $allCompanyIds);
    }
}
