<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\DebitNote;
use App\Models\Settlement;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfService
{
    /**
     * Resolve the company that must appear on a PDF:
     * branch -> active company (from X-Company-Id) -> first active company.
     */
    public function resolveCompany(?int $branchId = null): Company
    {
        $company = $branchId ? Branch::find($branchId)?->company : null;

        if (! $company && app()->bound('activeCompany')) {
            $company = app('activeCompany');
        }

        if (! $company) {
            $company = Company::query()->active()->orderBy('sort_order')->first();
        }

        if (! $company) {
            $legacy = CompanySetting::first();
            $company = new Company($legacy ? $legacy->only([
                'company_name', 'address', 'city', 'district', 'state', 'pincode',
                'gstin', 'pan', 'phone', 'email', 'terms_conditions',
                'authorized_signatory_title',
            ]) : []);
            $company->company_name = $company->company_name ?: 'Company';
        }

        return $company;
    }

    public function generateSettlementBillPdf(Settlement $settlement)
    {
        $settlement->load(['branch', 'vendor', 'product', 'invoice', 'qualityTests', 'charges', 'debitNotes.items']);

        $company = $this->resolveCompany($settlement->branch_id);
        $company->state_code = $company->state_code ?: substr((string) $company->gstin, 0, 2);

        $origInv = (float)($settlement->original_invoice_value ?? 0);
        $branVal = (float)($settlement->bran_value ?? 0);
        $manualAdj = (float)($settlement->manual_adjustment ?? 0);
        $debitNote = $settlement->debitNotes->first();

        // 1. Mathematically determine if this is a Credit Note or Debit Note
        $noteType = null;
        if ($origInv > 0) {
            $diff = ($origInv - $branVal) + $manualAdj;
            $noteType = ($diff < -0.001) ? 'credit' : 'debit';
        } elseif ($manualAdj != 0) {
            $noteType = ($manualAdj < -0.001) ? 'credit' : 'debit';
        } elseif (!empty($settlement->note_type)) {
            $noteType = strtolower($settlement->note_type);
        } elseif ($debitNote && !empty($debitNote->transaction_type)) {
            $noteType = in_array(strtolower($debitNote->transaction_type), ['credit', 'credit_note', 'cn']) ? 'credit' : 'debit';
        } else {
            $noteType = 'debit';
        }

        $prefix = ($noteType === 'credit') ? 'CN' : 'DN';

        // 2. Compute accurate rebate / bonus amount
        $calculatedRebate = 0.0;
        if ($origInv > 0) {
            $calculatedRebate = round(abs(($origInv - $branVal) + $manualAdj), 2);
        } elseif ($manualAdj != 0) {
            $calculatedRebate = round(abs($manualAdj), 2);
        }

        $rebateAmount = ((float)$settlement->rebate_amount > 0)
            ? (float)$settlement->rebate_amount
            : ($calculatedRebate > 0 ? $calculatedRebate : (float)($debitNote ? $debitNote->taxable_amount : 0));

        // 3. Synchronize existing debitNote in database if transaction_type or number is mismatched
        if ($debitNote) {
            $dnUpdates = [];
            if ($debitNote->transaction_type !== $noteType) {
                $dnUpdates['transaction_type'] = $noteType;
            }
            if ($noteType === 'credit' && str_starts_with($debitNote->debit_note_number, 'DN/')) {
                $dnUpdates['debit_note_number'] = 'CN/' . substr($debitNote->debit_note_number, 3);
            } elseif ($noteType === 'debit' && str_starts_with($debitNote->debit_note_number, 'CN/')) {
                $dnUpdates['debit_note_number'] = 'DN/' . substr($debitNote->debit_note_number, 3);
            }
            if ($rebateAmount > 0 && abs((float)$debitNote->taxable_amount - $rebateAmount) > 0.01) {
                $dnUpdates['taxable_amount'] = $rebateAmount;
                $isInter = (bool)$settlement->is_interstate;
                $cg = $isInter ? 0 : round(($rebateAmount * 2.5) / 100, 2);
                $sg = $isInter ? 0 : round(($rebateAmount * 2.5) / 100, 2);
                $ig = $isInter ? round(($rebateAmount * 5.0) / 100, 2) : 0;
                $dnUpdates['cgst_amount'] = $cg;
                $dnUpdates['sgst_amount'] = $sg;
                $dnUpdates['igst_amount'] = $ig;
                $dnUpdates['total_amount'] = round($rebateAmount + $cg + $sg + $ig, 2);
            }
            if ($noteType === 'credit' && ($debitNote->reason === 'Rebate on Oil Content Settlement' || empty($debitNote->reason))) {
                $dnUpdates['reason'] = 'Quality Premium / Price Credit on Oil Content Settlement';
            }
            if (!empty($dnUpdates)) {
                $debitNote->update($dnUpdates);
                $debitNote->fill($dnUpdates);
            }
        }

        // 4. Resolve Document Number
        $docNumber = $debitNote?->debit_note_number;
        if ($noteType === 'credit' && $docNumber && str_starts_with($docNumber, 'DN/')) {
            $docNumber = 'CN/' . substr($docNumber, 3);
        } elseif ($noteType === 'debit' && $docNumber && str_starts_with($docNumber, 'CN/')) {
            $docNumber = 'DN/' . substr($docNumber, 3);
        } elseif (!$docNumber) {
            $cleanNum = preg_replace('/[^0-9]/', '', $settlement->settlement_number ?? '1019') ?: '1019';
            $docNumber = $prefix . '/' . $cleanNum . '/' . ($settlement->financial_year ?? '26-27');
        }

        // 5. Party Invoice Value
        $invoiceValue = (float)($origInv > 0 
            ? $origInv 
            : (($settlement->invoice ? (float)$settlement->invoice->taxable_amount : ($noteType === 'credit' ? max(0, $branVal - $rebateAmount) : ($branVal + $rebateAmount)))));
        
        $cgstRate = (float)($settlement->cgst_rate > 0 ? $settlement->cgst_rate : 2.5);
        $sgstRate = (float)($settlement->sgst_rate > 0 ? $settlement->sgst_rate : 2.5);
        $igstRate = (float)($settlement->is_interstate ? ($settlement->igst_rate > 0 ? $settlement->igst_rate : 5.0) : 0);

        if ($settlement->is_interstate) {
            $invCgst = 0;
            $invSgst = 0;
            $invIgst = round(($invoiceValue * $igstRate) / 100, 2);
            $rebateCgst = 0;
            $rebateSgst = 0;
            $rebateIgst = round(($rebateAmount * $igstRate) / 100, 2);
        } else {
            $invCgst = round(($invoiceValue * $cgstRate) / 100, 2);
            $invSgst = round(($invoiceValue * $sgstRate) / 100, 2);
            $invIgst = 0;
            $rebateCgst = round(($rebateAmount * $cgstRate) / 100, 2);
            $rebateSgst = round(($rebateAmount * $sgstRate) / 100, 2);
            $rebateIgst = 0;
        }

        $invTotal = round($invoiceValue + $invCgst + $invSgst + $invIgst, 2);
        $rebateTotal = round($rebateAmount + $rebateCgst + $rebateSgst + $rebateIgst, 2);

        $rawRemarks = $debitNote?->remarks ?: $settlement->remarks;
        if ($noteType === 'credit') {
            if (empty($rawRemarks) || stripos($rawRemarks, 'debit') !== false) {
                $finalRemarks = 'Auto-generated Credit Note posted to vendor ledger';
            } else {
                $finalRemarks = $rawRemarks;
            }
        } else {
            if (empty($rawRemarks) || stripos($rawRemarks, 'credit') !== false) {
                $finalRemarks = 'Auto-generated Debit Note posted to vendor ledger';
            } else {
                $finalRemarks = $rawRemarks;
            }
        }

        $debitNoteData = [
            'transaction_type' => $noteType,
            'note_type_label' => ($noteType === 'credit') ? 'CREDIT NOTE' : 'DEBIT NOTE',
            'doc_number_label' => ($noteType === 'credit') ? 'CreditNote No:' : 'DebitNote No:',
            'particulars_label' => ($noteType === 'credit') 
                ? 'Credit (Oil Content & Quality Bonus / Premium Rate Difference)' 
                : 'Rebate (Oil Content & Quality Adjustment Difference)',
            'debit_note_number' => $docNumber,
            'date' => $debitNote?->date ?? $settlement->settlement_date,
            'invoice_number' => $settlement->bill_number ?: ($settlement->invoice?->invoice_number ?: '68'),
            'invoice_date' => $settlement->bill_date ?: ($settlement->invoice?->invoice_date ?: $settlement->settlement_date),
            'lot_number' => $settlement->lot_number,
            'rebate_amount' => $rebateAmount,
            'invoice_value' => $invoiceValue,
            'inv_cgst' => $invCgst,
            'inv_sgst' => $invSgst,
            'inv_igst' => $invIgst,
            'inv_total' => $invTotal,
            'rebate_cgst' => $rebateCgst,
            'rebate_sgst' => $rebateSgst,
            'rebate_igst' => $rebateIgst,
            'rebate_total' => $rebateTotal,
            'remarks' => $finalRemarks,
        ];

        $pdf = Pdf::loadView('pdf.settlement_bill', [
            'settlement' => $settlement,
            'company' => $company,
            'debitNote' => $debitNote,
            'debitNoteData' => $debitNoteData,
        ])
        ->setPaper('a4', 'portrait')
        ->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);

        return $pdf;
    }

    public function generateDebitNotePdf(DebitNote $debitNote)
    {
        $debitNote->load(['branch', 'vendor', 'settlement', 'invoice', 'items']);

        $company = $this->resolveCompany($debitNote->branch_id);
        $company->state_code = $company->state_code ?: substr((string) $company->gstin, 0, 2);

        $pdf = Pdf::loadView('pdf.debit_note', [
            'debitNote' => $debitNote,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function generateInwardPdf(\App\Models\InwardEntry $inward)
    {
        $inward->load(['branch', 'party', 'items.item', 'items.unit', 'items.grade', 'creator', 'confirmer']);

        $company = $this->resolveCompany($inward->branch_id);
        $company->state_code = $company->state_code ?: substr((string) $company->gstin, 0, 2);

        $pdf = Pdf::loadView('pdf.inward', [
            'inward' => $inward,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function generateOutwardPdf(\App\Models\OutwardEntry $outward)
    {
        $outward->load(['branch', 'party', 'items.item', 'items.lot', 'items.unit', 'items.grade', 'creator', 'confirmer']);

        $company = $this->resolveCompany($outward->branch_id);
        $company->state_code = $company->state_code ?: substr((string) $company->gstin, 0, 2);

        $pdf = Pdf::loadView('pdf.outward', [
            'outward' => $outward,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function generateProductionPdf(\App\Models\ProductionBatch $production)
    {
        $production->load([
            'sourceBranch',
            'destBranch',
            'party',
            'outputItem',
            'outputGrade',
            'inputs.lot.item',
            'inputs.item',
            'outputs.item',
            'outputs.lot',
            'creator',
            'confirmer',
        ]);

        $company = $this->resolveCompany($production->dest_branch_id ?: $production->source_branch_id);
        $company->state_code = $company->state_code ?: substr((string) $company->gstin, 0, 2);

        $pdf = Pdf::loadView('pdf.production', [
            'production' => $production,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        return $pdf;
    }
}

