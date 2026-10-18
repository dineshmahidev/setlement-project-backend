<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'item_id', 'grade_id', 'lot_number',
        'oil_pct', 'opening_qty', 'received_qty', 'issued_qty',
        'available_qty', 'reserved_qty', 'unit_id', 'unit_cost',
        'stock_value', 'source', 'source_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'oil_pct' => 'decimal:2',
            'opening_qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'issued_qty' => 'decimal:3',
            'available_qty' => 'decimal:3',
            'reserved_qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'stock_value' => 'decimal:2',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function branch() { return $this->belongsTo(Branch::class, 'branch_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function grade() { return $this->belongsTo(Grade::class); }
    public function unit() { return $this->belongsTo(Unit::class); }

    public function movements()
    {
        return $this->hasMany(InventoryTransaction::class, 'reference_id')
            ->where('reference_type', 'LOT');
    }
}
