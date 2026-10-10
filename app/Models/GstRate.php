<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GstRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'is_default',
        'status',
    ];

    protected $casts = [
        'cgst_rate' => 'decimal:3',
        'sgst_rate' => 'decimal:3',
        'igst_rate' => 'decimal:3',
        'is_default' => 'boolean',
    ];
}
