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

        $debitNote = $settlement->debitNotes->first();
        $noteType = $debitNote ? ($debitNote->transaction_type ?: 'debit') : 'debit';
        $prefix = ($noteType === 'credit') ? 'CN' : 'DN';
        
        $rebateAmount = (float)($settlement->rebate_amount ?? ($debitNote ? $debitNote->taxable_amount : 0));
        $invoiceValue = (float)($settlement->original_invoice_value > 0 
            ? $settlement->original_invoice_value 
            : (($settlement->invoice ? $settlement->invoice->taxable_amount : ($settlement->bran_value + $rebateAmount))));
        
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

        $debitNoteData = [
            'transaction_type' => $noteType,
            'note_type_label' => ($noteType === 'credit') ? 'CREDIT NOTE' : 'DEBIT NOTE',
            'doc_number_label' => ($noteType === 'credit') ? 'CreditNote No:' : 'DebitNote No:',
            'particulars_label' => ($noteType === 'credit') 
                ? 'Credit (Oil Content & Quality Bonus / Premium Rate Difference)' 
                : 'Rebate (Oil Content & Quality Adjustment Difference)',
            'debit_note_number' => $debitNote?->debit_note_number ?? ($prefix . '/' . preg_replace('/[^0-9]/', '', $settlement->settlement_number ?? '1019') . '/' . ($settlement->financial_year ?? '26-27')),
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
            'remarks' => $debitNote?->remarks ?? $settlement->remarks,
        ];

        $pdf = Pdf::loadView('pdf.settlement_bill', [
            'settlement' => $settlement,
            'company' => $company,
            'debitNote' => $debitNote,
            'debitNoteData' => $debitNoteData,
        ])->setPaper('a4', 'portrait');

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
}
