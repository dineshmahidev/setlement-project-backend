<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Settlement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'settlement_number',
        'financial_year',
        'branch_id',
        'vendor_id',
        'product_id',
        'invoice_id',
        'lot_number',
        'bill_number',
        'bill_date',
        'settlement_date',
        'vehicle_number',
        'net_weight_mt',
        'jute_bag',
        'pp_bag',
        'final_weight_mt',
        'final_weight_kg',
        'oil_content',
        'ffa',
        'ss',
        'fibre',
        'moisture',
        'iv',
        'calcium',
        'protein',
        'contract_unit_rate',
        'final_unit_rate',
        'oil_quantity_kg',
        'bran_value',
        'bag_amount',
        'loading_charges',
        'unloading_charges',
        'tds_rate',
        'tds',
        'advance_paid',
        'lorry_freight',
        'other_charges',
        'original_invoice_value',
        'rebate_amount',
        'manual_adjustment',
        'manual_adjustment_reason',
        'subtotal_amount',
        'is_interstate',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_amount',
        'net_payable',
        'status',
        'cancellation_reason',
        'remarks',
        'created_by',
        'updated_by',
        'approved_by',
        'posted_at',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'settlement_date' => 'date',
        'posted_at' => 'datetime',
        'net_weight_mt' => 'decimal:3',
        'jute_bag' => 'decimal:3',
        'pp_bag' => 'decimal:3',
        'final_weight_mt' => 'decimal:3',
        'final_weight_kg' => 'decimal:3',
        'oil_content' => 'decimal:3',
        'ffa' => 'string',
        'ss' => 'string',
        'fibre' => 'string',
        'moisture' => 'string',
        'iv' => 'string',
        'calcium' => 'string',
        'protein' => 'string',
        'contract_unit_rate' => 'decimal:2',
        'final_unit_rate' => 'decimal:2',
        'oil_quantity_kg' => 'decimal:3',
        'bran_value' => 'decimal:2',
        'bag_amount' => 'decimal:2',
        'loading_charges' => 'decimal:2',
        'unloading_charges' => 'decimal:2',
        'tds_rate' => 'decimal:3',
        'tds' => 'decimal:2',
        'advance_paid' => 'decimal:2',
        'lorry_freight' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'original_invoice_value' => 'decimal:2',
        'rebate_amount' => 'decimal:2',
        'manual_adjustment' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'is_interstate' => 'boolean',
        'cgst_rate' => 'decimal:3',
        'sgst_rate' => 'decimal:3',
        'igst_rate' => 'decimal:3',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'net_payable' => 'decimal:2',
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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function qualityTests()
    {
        return $this->hasMany(SettlementQualityTest::class);
    }

    public function charges()
    {
        return $this->hasMany(SettlementCharge::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(DebitNote::class);
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

    public function isDebitNoteSettlement(): bool
    {
        $noteType = strtolower($this->note_type ?? '');
        if ($noteType === 'debit' || $noteType === 'dn') {
            return true;
        }
        if ($noteType === 'credit' || $noteType === 'cn') {
            return false;
        }

        $firstNote = $this->debitNotes()->first();
        if ($firstNote) {
            $txType = strtolower($firstNote->transaction_type ?? '');
            if ($txType === 'debit' || str_starts_with($firstNote->debit_note_number, 'DN/')) {
                return true;
            }
            if ($txType === 'credit' || str_starts_with($firstNote->debit_note_number, 'CN/')) {
                return false;
            }
        }

        $origInv = (float)($this->original_invoice_value ?? 0);
        $branVal = (float)($this->bran_value ?? 0);
        $manualAdj = (float)($this->manual_adjustment ?? 0);

        if ($origInv > 0 || $manualAdj != 0) {
            $diff = ($origInv > 0) ? (($origInv - $branVal) + $manualAdj) : $manualAdj;
            if ($diff > 0.001) {
                return true;
            }
        }

        return false;
    }

    public function getDebitCreditAmount(): float
    {
        $firstNote = $this->debitNotes()->first();
        if ($firstNote) {
            $tot = (float)$firstNote->total_amount;
            if ($tot > 0) {
                return $tot;
            }
            $tax = (float)$firstNote->taxable_amount;
            if ($tax > 0) {
                return $tax;
            }
        }

        if ((float)$this->rebate_amount > 0) {
            return (float)$this->rebate_amount;
        }

        $origInv = (float)($this->original_invoice_value ?? 0);
        $branVal = (float)($this->bran_value ?? 0);
        $manualAdj = (float)($this->manual_adjustment ?? 0);

        if ($origInv > 0) {
            $diff = abs(($origInv - $branVal) + $manualAdj);
            if ($diff > 0.001) {
                return $diff;
            }
        }

        return max(0, (float)$this->net_payable > 0 
            ? (float)$this->net_payable 
            : ((float)$this->total_amount > 0 ? (float)$this->total_amount : (float)$this->bran_value));
    }
}
