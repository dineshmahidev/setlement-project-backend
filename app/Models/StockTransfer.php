<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'transfer_number', 'date', 'from_branch_id', 'to_branch_id',
        'vehicle_id', 'item_id', 'quantity', 'unit_id', 'lot_number',
        'driver_name', 'driver_mobile', 'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'quantity' => 'decimal:3'];
    }

    public function fromBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function toBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
