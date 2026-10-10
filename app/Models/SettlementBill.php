<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementBill extends Model
{
    protected $fillable = [
        'bill_number', 'bill_date', 'lot_number', 'branch_id', 'vendor_id',
        'vehicle_id', 'item_id', 'vendor_snapshot', 'po_number',
        'net_weight', 'final_weight', 'billing_quantity', 'jute_bag_quantity',
        'container_unit_rate', 'final_unit_rate', 'manual_quantity',
        'billing_quantity_source', 'rate_mode', 'rate_rule', 'rate_calculated',
        'total_value', 'charges_total', 'additive_charges', 'deductive_charges',
        'taxable_amount', 'gst_type', 'gst_rate', 'cgst_amount', 'sgst_amount',
        'igst_amount', 'net_payable', 'status', 'post_to_inventory',
        'inventory_posted', 'inventory_posted_at', 'remarks',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'inventory_posted_at' => 'datetime',
            'post_to_inventory' => 'boolean',
            'inventory_posted' => 'boolean',
            'net_weight' => 'decimal:3',
            'final_weight' => 'decimal:3',
            'billing_quantity' => 'decimal:3',
            'jute_bag_quantity' => 'decimal:3',
            'container_unit_rate' => 'decimal:4',
            'final_unit_rate' => 'decimal:4',
            'manual_quantity' => 'decimal:3',
            'total_value' => 'decimal:2',
            'charges_total' => 'decimal:2',
            'additive_charges' => 'decimal:2',
            'deductive_charges' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'gst_rate' => 'decimal:4',
        ];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function qc(): HasMany { return $this->hasMany(SettlementBillQc::class); }
    public function charges(): HasMany { return $this->hasMany(SettlementBillCharge::class); }
    public function taxes(): HasMany { return $this->hasMany(SettlementBillTax::class); }
}
