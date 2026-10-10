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

    public function allowedBranchIds(): ?array
    {
        if ($this->hasRole(['super_admin', 'superadmin', 'Super Admin', 'SUPER ADMIN'])) {
            return null;
        }

        $ids = $this->branches()->pluck('branches.id')->toArray();
        if (empty($ids) && $this->default_branch_id) {
            $ids = [$this->default_branch_id];
        }

        return !empty($ids) ? $ids : null;
    }
}
