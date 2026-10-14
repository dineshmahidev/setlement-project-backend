<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'slug',
        'company_name',
        'tagline',
        'sub_tagline',
        'about_us',
        'cms_hero_title',
        'cms_hero_subtitle',
        'cms_services',
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
        'phone_2',
        'email',
        'website',
        'map_url',
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
        'logo_transparent_path',
        'banner_path',
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

    /**
     * Resolve the dynamic base64 logo for PDF rendering (DomPDF).
     */
    public function getLogoBase64Attribute(): ?string
    {
        if ($this->logo_transparent_path && str_starts_with($this->logo_transparent_path, 'data:image/')) {
            return $this->logo_transparent_path;
        }
        if ($this->logo_path && str_starts_with($this->logo_path, 'data:image/')) {
            return $this->logo_path;
        }

        $candidates = [
            $this->logo_transparent_path,
            $this->logo_path,
        ];

        // Specific fallbacks based on company identity
        $identity = strtolower(str_replace([' ', '-', '_', '.'], '', $this->slug ?: ($this->code ?: $this->company_name)));
        if (str_contains($identity, 'sm') || str_contains($identity, 'smtraders')) {
            $candidates[] = '/logos/sm_traders_transparent.png';
            $candidates[] = '/logos/sm_traders.png';
        } elseif (str_contains($identity, 'feed') || str_contains($identity, 'shriee') || str_contains($identity, 'shreefeed')) {
            $candidates[] = '/logos/shree_feeds_transparent.png';
            $candidates[] = '/logos/shree_feeds.png';
        } else {
            $candidates[] = '/logos/laxmi_traders_transparent.png';
            $candidates[] = '/logos/laxmi_traders.png';
        }
        $candidates[] = '/logos/shree_laxmi_transparent.png';

        foreach (array_filter($candidates) as $rel) {
            $clean = ltrim($rel, '/\\');
            $checkPaths = [
                public_path($clean),
                base_path('../frontend/public/' . $clean),
                storage_path('app/public/' . $clean),
                base_path('public/' . $clean),
            ];
            foreach ($checkPaths as $p) {
                if (file_exists($p) && is_file($p)) {
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION)) ?: 'png';
                    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
                }
            }
        }
        return null;
    }

    /**
     * Resolve the dynamic base64 digital signature for PDF rendering (DomPDF).
     */
    public function getSignatureBase64Attribute(): ?string
    {
        if ($this->signature_path && str_starts_with($this->signature_path, 'data:image/')) {
            return $this->signature_path;
        }

        $candidates = [
            $this->signature_path,
        ];

        // Specific fallbacks based on company identity
        $identity = strtolower(str_replace([' ', '-', '_', '.'], '', $this->slug ?: ($this->code ?: $this->company_name)));
        if (str_contains($identity, 'sm') || str_contains($identity, 'smtraders')) {
            $candidates[] = '/signatures/sm_signature.png';
        } elseif (str_contains($identity, 'feed') || str_contains($identity, 'shriee') || str_contains($identity, 'shreefeed')) {
            $candidates[] = '/signatures/shriee_signature.png';
        } else {
            $candidates[] = '/signatures/laxmi_signature.png';
        }
        $candidates[] = '/signatures/default_signature.png';

        foreach (array_filter($candidates) as $rel) {
            $clean = ltrim($rel, '/\\');
            $checkPaths = [
                public_path($clean),
                base_path('../frontend/public/' . $clean),
                storage_path('app/public/' . $clean),
                base_path('public/' . $clean),
            ];
            foreach ($checkPaths as $p) {
                if (file_exists($p) && is_file($p)) {
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION)) ?: 'png';
                    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
                }
            }
        }
        return null;
    }
}
