<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_code',
        'branch_name',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'gstin',
        'phone',
        'email',
        'status',
    ];

    protected $appends = ['name', 'code'];

    public function getNameAttribute(): string
    {
        return $this->attributes['branch_name'] ?? '';
    }

    public function getCodeAttribute(): string
    {
        return $this->attributes['branch_code'] ?? '';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(DebitNote::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(VendorLedger::class);
    }
}
