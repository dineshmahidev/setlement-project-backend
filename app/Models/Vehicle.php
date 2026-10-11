<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_number',
        'vehicle_type',
        'driver_name',
        'driver_mobile',
        'status',
    ];

    protected $appends = ['number', 'name'];

    public function getNumberAttribute(): string
    {
        return $this->attributes['vehicle_number'] ?? '';
    }

    public function getNameAttribute(): string
    {
        return $this->attributes['vehicle_number'] ?? '';
    }
}
