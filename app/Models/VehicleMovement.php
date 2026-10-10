<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMovement extends Model
{
    protected $fillable = [
        'date', 'vehicle_id', 'driver_name', 'driver_mobile', 'branch_id',
        'transaction_type', 'vendor_id', 'customer', 'item_id', 'quantity',
        'lot_number', 'reference_type', 'reference_id', 'reference_number',
        'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'quantity' => 'decimal:3'];
    }

    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
}
