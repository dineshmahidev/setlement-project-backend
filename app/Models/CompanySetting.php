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
}
