<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'transaction_number', 'date', 'branch_id', 'item_id', 'type',
        'quantity', 'signed_quantity', 'unit_id', 'vehicle_id',
        'driver_name', 'driver_mobile', 'vendor_id', 'customer', 'destination',
        'lot_number', 'reference_type', 'reference_id', 'source_reference',
        'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => 'decimal:3',
            'signed_quantity' => 'decimal:3',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
