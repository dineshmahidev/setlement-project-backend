<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebitNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'debit_note_number',
        'financial_year',
        'branch_id',
        'vendor_id',
        'settlement_id',
        'invoice_id',
        'invoice_number',
        'invoice_date',
        'lot_number',
        'date',
        'reason',
        'transaction_type',
        'taxable_amount',
        'is_interstate',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_amount',
        'remarks',
        'status',
        'cancellation_reason',
        'created_by',
        'updated_by',
        'approved_by',
        'posted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'invoice_date' => 'date',
        'posted_at' => 'datetime',
        'is_interstate' => 'boolean',
        'taxable_amount' => 'decimal:2',
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

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items()
    {
        return $this->hasMany(DebitNoteItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
