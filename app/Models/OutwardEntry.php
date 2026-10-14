<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OutwardEntry extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'entry_number', 'company_id', 'branch_id', 'party_id', 'date',
        'dispatch_type', 'vehicle_number', 'driver_name', 'driver_mobile',
        'destination', 'total_amount', 'is_sale', 'payment_status',
        'status', 'notes', 'created_by', 'confirmed_by', 'confirmed_at',
        'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_amount' => 'decimal:2',
            'is_sale' => 'boolean',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function branch() { return $this->belongsTo(Branch::class, 'branch_id'); }
    public function party() { return $this->belongsTo(Vendor::class, 'party_id'); }
    public function items() { return $this->hasMany(OutwardItem::class, 'entry_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function confirmer() { return $this->belongsTo(User::class, 'confirmed_by'); }
}
