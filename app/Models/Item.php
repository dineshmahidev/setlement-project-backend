<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    protected $fillable = [
        'code', 'name', 'category_id', 'unit_id',
        'min_stock_level', 'max_stock_level', 'status',
    ];

    protected function casts(): array
    {
        return [
            'min_stock_level' => 'decimal:3',
            'max_stock_level' => 'decimal:3',
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
}
