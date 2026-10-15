<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_id',
        'mobile',
        'role_id',
        'default_branch_id',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function defaultBranch()
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('granted');
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'user_companies');
    }

    public function hasRole(string|array $roles): bool
    {
        if (!$this->role) {
            return false;
        }

        $current = strtoupper(trim($this->role->name));

        if (is_array($roles)) {
            $normalized = array_map(fn ($r) => strtoupper(trim($r)), $roles);
            return in_array($current, $normalized);
        }

        return $current === strtoupper(trim($roles));
    }

    public function hasPermission(string $permission): bool
    {
        // Super admin / Master admin has all permissions
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Admin role: can access settlement, inventory, and user management (plus operations like items, vendors, vehicles, debit notes, reports)
        if ($this->isAdmin()) {
            $forbiddenForAdmin = [
                'super_admin',
                'branches.manage',
                'branches.create',
                'branches.delete',
                'companies.manage',
                'companies.create',
                'companies.delete',
                'settings.manage',
                'settings.edit',
                'audit.view',
                'cms.manage',
            ];
            if (in_array($permission, $forbiddenForAdmin, true)) {
                return false;
            }

            return true;
        }

        // Branch Manager & Staff: only access to inventory section
        if ($this->isBranchManager() || $this->isStaff()) {
            if (str_starts_with($permission, 'inventory.') || in_array($permission, [
                'items.view', 'items.create', 'items.edit',
                'products.view', 'products.create', 'products.edit',
                'vehicle.view', 'vehicles.view', 'vehicles.create', 'vehicles.edit',
                'dashboard.view', 'reports.view',
            ], true)) {
                return true;
            }

            return false;
        }

        // Check user specific permissions
        $userPerm = $this->permissions()->where('name', $permission)->first();
        if ($userPerm) {
            return (bool) $userPerm->pivot->granted;
        }

        // Check role permissions
        if ($this->role) {
            return $this->role->permissions()->where('name', $permission)->exists();
        }

        return false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole([
            'super_admin',
            'superadmin',
            'Super Admin',
            'SUPER ADMIN',
            'master_admin',
            'masteradmin',
            'Master Admin',
            'MASTER ADMIN',
        ]) || $this->email === 'admin@laxmitraders.com';
    }

    public function isAdmin(): bool
    {
        return ! $this->isSuperAdmin() && $this->hasRole([
            'admin',
            'Admin',
            'ADMIN',
        ]);
    }

    public function isBranchManager(): bool
    {
        return $this->hasRole([
            'branch_manager',
            'Branch Manager',
            'BRANCH MANAGER',
            'manager',
            'Manager',
        ]);
    }

    public function isStaff(): bool
    {
        return $this->hasRole([
            'staff',
            'Staff',
            'STAFF',
            'operator',
            'Operator',
            'Staff / Operator',
            'BILLING STAFF',
            'billing staff',
            'INVENTORY STAFF',
            'inventory staff',
            'ACCOUNTS STAFF',
            'accounts staff',
            'VIEWER',
            'viewer',
        ]);
    }

    public function canPermission(string $permission): bool
    {
        return $this->hasPermission($permission);
    }

    /**
     * null  => unrestricted (super admin, no company selected)
     * array => explicitly allowed company ids
     */
    public function allowedCompanyIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        $ids = $this->companies()->pluck('companies.id')->unique()->values()->all();

        if (empty($ids)) {
            $ids = $this->branches()->pluck('branches.company_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($ids) && $this->defaultBranch?->company_id) {
            $ids = [$this->defaultBranch->company_id];
        }

        return ! empty($ids) ? $ids : null;
    }

    /**
     * Concrete list of company ids this user may ever see.
     */
    public function companyIds(): array
    {
        return $this->allowedCompanyIds() ?? Company::query()->pluck('id')->all();
    }

    /**
     * null  => unrestricted (super admin with no company filter)
     * array => branch ids allowed inside the given company
     */
    public function allowedBranchIds(?int $companyId = null): ?array
    {
        if ($this->isSuperAdmin()) {
            if ($companyId) {
                return Branch::query()->where('company_id', $companyId)->pluck('id')->all();
            }

            return null;
        }

        $query = $this->branches();

        if ($companyId) {
            $query->where('branches.company_id', $companyId);
        }

        $ids = $query->pluck('branches.id')->unique()->values()->all();

        if (empty($ids) && $this->default_branch_id) {
            $default = $this->defaultBranch;
            if (! $companyId || ($default && (int) $default->company_id === $companyId)) {
                $ids = [$this->default_branch_id];
            }
        }

        return $ids;
    }
}
