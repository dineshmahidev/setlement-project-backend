<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_code',
        'vendor_name',
        'business_name',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'gstin',
        'pan',
        'phone',
        'email',
        'contact_person',
        'bank_name',
        'account_number',
        'ifsc',
        'opening_balance',
        'status',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
    ];

    protected $appends = ['name', 'code', 'current_balance'];

    public function getNameAttribute(): string
    {
        return $this->attributes['vendor_name'] ?? $this->attributes['business_name'] ?? '';
    }

    public function getCodeAttribute(): string
    {
        return $this->attributes['vendor_code'] ?? '';
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

    public function ledger()
    {
        return $this->hasMany(VendorLedger::class);
    }

    public function getCurrentBalanceAttribute()
    {
        $lastLedger = $this->ledger()->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->first();
        return $lastLedger ? $lastLedger->balance : $this->opening_balance;
    }
}
