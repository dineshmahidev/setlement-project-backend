<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementBillCharge extends Model
{
    protected $fillable = [
        'settlement_bill_id', 'charge_type_id', 'name', 'mode',
        'direction', 'amount', 'calculation_rule', 'basis',
        'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'amount' => 'decimal:4',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SettlementBill::class, 'settlement_bill_id');
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }
}
