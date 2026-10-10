<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargeType extends Model
{
    protected $fillable = [
        'name', 'code', 'mode', 'direction',
        'calculation_rule', 'default_amount', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'default_amount' => 'decimal:4',
        ];
    }
}
