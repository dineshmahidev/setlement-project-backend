<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutwardItem extends Model
{
    protected $fillable = [
        'entry_id', 'item_id', 'lot_id', 'quantity', 'unit_id',
        'oil_pct', 'grade_id', 'rate', 'amount', 'discount',
        'additional_charges', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'oil_pct' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'additional_charges' => 'decimal:2',
        ];
    }

    public function entry() { return $this->belongsTo(OutwardEntry::class, 'entry_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function lot() { return $this->belongsTo(Lot::class); }
    public function grade() { return $this->belongsTo(Grade::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
