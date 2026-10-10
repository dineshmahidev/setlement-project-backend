<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VendorLedger extends Model
{
    use HasFactory;

    protected $table = 'vendor_ledger';

    protected $fillable = [
        'vendor_id',
        'branch_id',
        'transaction_date',
        'document_type',
        'document_id',
        'document_number',
        'lot_number',
        'description',
        'debit',
        'credit',
        'balance',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Post a transaction to the vendor ledger with automatic running balance
     */
    public static function postTransaction(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $vendorId = $data['vendor_id'];
            $vendor = Vendor::findOrFail($vendorId);

            $lastEntry = self::where('vendor_id', $vendorId)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $previousBalance = $lastEntry ? (float)$lastEntry->balance : (float)$vendor->opening_balance;
            
            $debit = (float)($data['debit'] ?? 0);
            $credit = (float)($data['credit'] ?? 0);
            
            // Standard accounting for vendor (payable account):
            // Invoices/Settlement net payables credited (we owe vendor),
            // Debit Notes/Payments debited (reduces vendor liability)
            // Or if debit balance represents amount receivable and credit represents payable:
            // Let's ensure Balance = Previous Balance + Debit - Credit (or + Credit - Debit according to standard convention)
            // Conventional Vendor Ledger in Indian Agro accounting:
            // Purchase/Invoice -> Credit to vendor account (+ liability)
            // Payment / Debit Note (Rebate) -> Debit to vendor account (- liability)
            // Balance = Previous Balance - Debit + Credit (Payable is positive)
            $newBalance = $previousBalance - $debit + $credit;

            return self::create([
                'vendor_id' => $vendorId,
                'branch_id' => $data['branch_id'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'document_type' => $data['document_type'],
                'document_id' => $data['document_id'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'description' => $data['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $newBalance,
                'created_by' => auth()->id() ?? $data['created_by'] ?? null,
            ]);
        });
    }
}
