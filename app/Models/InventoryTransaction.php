<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'transaction_number', 'date', 'company_id', 'branch_id', 'item_id', 'type',
        'quantity', 'signed_quantity', 'rate', 'amount', 'unit_id', 'vehicle_id',
        'driver_name', 'driver_mobile', 'vendor_id', 'party_id', 'party_type',
        'customer', 'destination', 'oil_pct', 'grade_id', 'lot_number',
        'reference_type', 'reference_id', 'source_reference',
        'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => 'decimal:3',
            'signed_quantity' => 'decimal:3',
            'oil_pct' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function grade(): BelongsTo { return $this->belongsTo(Grade::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
