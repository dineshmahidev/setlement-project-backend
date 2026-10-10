<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementBillQc extends Model
{
    protected $table = 'settlement_bill_qc';

    protected $fillable = [
        'settlement_bill_id', 'name', 'key', 'value',
        'unit', 'remarks', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SettlementBill::class, 'settlement_bill_id');
    }
}
