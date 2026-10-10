<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementBillTax extends Model
{
    protected $fillable = ['settlement_bill_id', 'type', 'rate', 'amount'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'amount' => 'decimal:2'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SettlementBill::class, 'settlement_bill_id');
    }
}
