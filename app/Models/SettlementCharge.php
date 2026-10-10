<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'charge_name',
        'charge_type',
        'amount',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }
}
