<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'slug',
        'company_name',
        'tagline',
        'sub_tagline',
        'trade_name',
        'short_name',
        'address',
        'city',
        'district',
        'state',
        'state_code',
        'pincode',
        'gstin',
        'pan',
        'cin',
        'phone',
        'email',
        'website',
        'bank_name',
        'branch_bank',
        'account_number',
        'ifsc_code',
        'settlement_prefix',
        'debit_note_prefix',
        'invoice_prefix',
        'rounding_mode',
        'gst_api_provider',
        'gst_api_key',
        'gst_api_secret',
        'gst_api_enabled',
        'terms_conditions',
        'authorized_signatory_title',
        'logo_path',
        'signature_path',
        'status',
        'sort_order',
    ];

    protected $hidden = [
        'gst_api_key',
        'gst_api_secret',
    ];

    protected function casts(): array
    {
        return [
            'gst_api_enabled' => 'boolean',
        ];
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_companies');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Company bound to the current request (X-Company-Id), else the first active one.
     */
    public static function current(): ?self
    {
        if (app()->bound('activeCompany')) {
            return app('activeCompany');
        }

        return static::query()->active()->orderBy('sort_order')->first();
    }

    public static function resolve(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return static::query()
            ->where('id', $value)
            ->orWhere('slug', $value)
            ->orWhere('code', $value)
            ->first();
    }
}
