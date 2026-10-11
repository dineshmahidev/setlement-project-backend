<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'product_name',
        'product_code',
        'product_type',
        'unit',
        'default_rate',
        'default_cgst',
        'default_sgst',
        'default_igst',
        'status',
    ];

    protected $casts = [
        'default_rate' => 'decimal:2',
        'default_cgst' => 'decimal:3',
        'default_sgst' => 'decimal:3',
        'default_igst' => 'decimal:3',
    ];

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
