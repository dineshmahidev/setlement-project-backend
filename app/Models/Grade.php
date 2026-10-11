<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'material_type',
        'min_oil_pct', 'max_oil_pct', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return [
            'min_oil_pct' => 'decimal:2',
            'max_oil_pct' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Best matching grade for a measured oil % within a material type (if given). */
    public static function matchForOil(?int $companyId, float $oilPct, ?string $materialType = null): ?self
    {
        $q = static::where('status', 'ACTIVE')
            ->where(function ($qq) use ($companyId) {
                $qq->whereNull('company_id');
                if ($companyId) $qq->orWhere('company_id', $companyId);
            })
            ->where('min_oil_pct', '<=', $oilPct)
            ->where('max_oil_pct', '>=', $oilPct);

        if ($materialType) {
            $q->where(fn ($qq) => $qq->whereNull('material_type')->orWhere('material_type', $materialType));
        }

        return $q->orderByRaw('company_id IS NULL')->orderBy('min_oil_pct', 'desc')->first();
    }
}
