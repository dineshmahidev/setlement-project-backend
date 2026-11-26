<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'product_id',
        'lot_number',
        'net_weight_mt',
        'jute_bag',
        'pp_bag',
        'final_weight_mt',
        'final_weight_kg',
        'oil_content',
        'ffa',
        'ss',
        'fibre',
        'moisture',
        'contract_unit_rate',
        'final_unit_rate',
        'oil_quantity_kg',
        'bran_value',
        'bag_amount',
        'remarks',
        'sort_order',
    ];

    protected $casts = [
        'net_weight_mt' => 'decimal:3',
        'jute_bag' => 'decimal:3',
        'pp_bag' => 'decimal:3',
        'final_weight_mt' => 'decimal:3',
        'final_weight_kg' => 'decimal:3',
        'oil_content' => 'decimal:3',
        'contract_unit_rate' => 'decimal:2',
        'final_unit_rate' => 'decimal:2',
        'oil_quantity_kg' => 'decimal:3',
        'bran_value' => 'decimal:2',
        'bag_amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
