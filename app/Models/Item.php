<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'company_id', 'code', 'name', 'material_type', 'default_oil_pct', 'description',
        'category_id', 'unit_id', 'min_stock_level', 'max_stock_level', 'status',
    ];

    protected function casts(): array
    {
        return [
            'min_stock_level' => 'decimal:3',
            'max_stock_level' => 'decimal:3',
            'default_oil_pct' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }
}
