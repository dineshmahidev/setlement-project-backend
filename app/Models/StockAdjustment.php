<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'adjustment_number', 'date', 'branch_id', 'item_id', 'direction',
        'quantity', 'unit_id', 'reason', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'quantity' => 'decimal:3'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
}
