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
        // Super admin has all permissions
        if ($this->hasRole(['super_admin', 'superadmin', 'Super Admin', 'SUPER ADMIN'])) {
            return true;
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
        return $this->hasRole(['super_admin', 'superadmin', 'Super Admin', 'SUPER ADMIN']);
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
