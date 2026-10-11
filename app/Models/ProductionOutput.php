<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOutput extends Model
{
    protected $fillable = ['batch_id', 'lot_id', 'item_id', 'quantity', 'oil_pct', 'grade_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'oil_pct' => 'decimal:2'];
    }

    public function batch() { return $this->belongsTo(ProductionBatch::class, 'batch_id'); }
    public function lot() { return $this->belongsTo(Lot::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function grade() { return $this->belongsTo(Grade::class); }
}
