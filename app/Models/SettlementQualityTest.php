<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementQualityTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'parameter_name',
        'parameter_value',
        'standard_value',
        'remarks',
    ];

    protected $casts = [
        'parameter_value' => 'string',
        'standard_value' => 'string',
    ];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }
}
