<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_type',
        'prefix',
        'current_number',
        'financial_year',
        'branch_id',
    ];

    /**
     * Generate next document number safely with DB lock
     * 
     * Patterns:
     * Settlement Bill: SB/{YEAR}/{BRANCH}/{NUMBER}  e.g. SB/2026/BR1/001
     * Debit Note:      DN/{NUMBER}/{FINANCIAL_YEAR}  e.g. DN/1019/26-27
     * Invoice:         INV/{NUMBER}/{FINANCIAL_YEAR} e.g. INV/001/26-27
     */
    public static function getNextNumber(string $documentType, ?int $branchId = null, ?string $date = null): string
    {
        $fy = FinancialYear::getFinancialYearForDate($date);
        $year = date('Y', $date ? strtotime($date) : time());

        $branch = $branchId ? Branch::find($branchId) : null;
        $branchCode = $branch ? $branch->branch_code : 'HQ';

        $companySetting = CompanySetting::first();
        
        $prefix = match($documentType) {
            'settlement' => $companySetting?->settlement_prefix ?? 'SB',
            'debit_note' => $companySetting?->debit_note_prefix ?? 'DN',
            'credit_note' => $companySetting?->credit_note_prefix ?? 'CN',
            'invoice' => $companySetting?->invoice_prefix ?? 'INV',
            default => strtoupper($documentType),
        };

        return DB::transaction(function () use ($documentType, $prefix, $fy, $year, $branchId, $branchCode) {
            $seq = self::where('document_type', $documentType)
                ->where('financial_year', $fy)
                ->where(function ($q) use ($branchId) {
                    if ($branchId) {
                        $q->where('branch_id', $branchId);
                    } else {
                        $q->whereNull('branch_id');
                    }
                })
                ->lockForUpdate()
                ->first();

            if (!$seq) {
                $seq = self::create([
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'current_number' => 0,
                    'financial_year' => $fy,
                    'branch_id' => $branchId,
                ]);
            }

            $seq->increment('current_number');
            $nextNum = $seq->current_number;
            $formattedNum = str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);

            // Format based on standard specifications
            if ($documentType === 'settlement') {
                return "{$prefix}/{$year}/{$branchCode}/{$formattedNum}";
            } elseif ($documentType === 'debit_note') {
                return "{$prefix}/{$formattedNum}/{$fy}";
            } elseif ($documentType === 'invoice') {
                return "{$prefix}/{$formattedNum}/{$fy}";
            }

            return "{$prefix}/{$formattedNum}/{$fy}";
        });
    }
}
