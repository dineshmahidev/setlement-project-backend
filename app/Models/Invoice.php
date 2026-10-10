<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'invoice_date',
        'branch_id',
        'vendor_id',
        'product_id',
        'lot_number',
        'vehicle_number',
        'net_weight_mt',
        'rate',
        'taxable_amount',
        'is_interstate',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_amount',
        'status',
        'cancellation_reason',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'net_weight_mt' => 'decimal:3',
        'rate' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'is_interstate' => 'boolean',
        'cgst_rate' => 'decimal:3',
        'sgst_rate' => 'decimal:3',
        'igst_rate' => 'decimal:3',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(DebitNote::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
