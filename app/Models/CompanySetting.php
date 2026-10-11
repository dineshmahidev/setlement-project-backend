<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'trade_name',
        'logo_path',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'gstin',
        'cin',
        'pan',
        'phone',
        'email',
        'website',
        'bank_name',
        'account_number',
        'ifsc_code',
        'branch_bank',
        'rounding_mode',
        'gst_api_provider',
        'gst_api_key',
        'gst_api_secret',
        'gst_api_enabled',
        'settlement_prefix',
        'debit_note_prefix',
        'invoice_prefix',
        'terms_conditions',
        'authorized_signatory_title',
        'signature_path',
    ];

    protected $casts = [
        'gst_api_enabled' => 'boolean',
    ];

    /**
     * Legacy settings lookup: prefer the active company column, then the
     * single company_settings row, otherwise the caller's default.
     */
    public static function getValue(string $key, $default = null)
    {
        $company = Company::current();
        if ($company && $company->hasAttribute($key)) {
            return $company->getAttribute($key) ?? $default;
        }

        $row = static::query()->first();
        if ($row && $row->hasAttribute($key)) {
            return $row->getAttribute($key) ?? $default;
        }

        return $default;
    }
}
