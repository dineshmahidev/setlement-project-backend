<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('settlement') ? $this->route('settlement')->id : null;

        return [
            'settlement_number' => ['nullable', 'string', 'max:50', 'unique:settlements,settlement_number,' . $id],
            'branch_id' => ['required', 'exists:branches,id'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'lot_number' => ['nullable', 'string', 'max:50'],
            'bill_number' => ['nullable', 'string', 'max:50'],
            'bill_date' => ['nullable', 'date'],
            'settlement_date' => ['required', 'date'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            
            // Weight Details
            'net_weight_mt' => ['required', 'numeric', 'min:0'],
            'jute_bag' => ['nullable', 'numeric', 'min:0'],
            'pp_bag' => ['nullable', 'numeric', 'min:0'],
            'final_weight_mt' => ['nullable', 'numeric', 'min:0'],
            
            // Quality Test
            'oil_content' => ['required', 'numeric', 'min:0', 'max:100'],
            'ffa' => ['nullable'],
            'ss' => ['nullable'],
            'fibre' => ['nullable'],
            'moisture' => ['nullable'],
            'iv' => ['nullable'],
            'calcium' => ['nullable'],
            'protein' => ['nullable'],
            
            // Rates
            'contract_unit_rate' => ['nullable', 'numeric', 'min:0'],
            'final_unit_rate' => ['required', 'numeric', 'min:0'],
            
            // Charges
            'bag_amount' => ['nullable', 'numeric', 'min:0'],
            'loading_charges' => ['nullable', 'numeric', 'min:0'],
            'unloading_charges' => ['nullable', 'numeric', 'min:0'],
            'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tds' => ['nullable', 'numeric', 'min:0'],
            'advance_paid' => ['nullable', 'numeric', 'min:0'],
            'lorry_freight' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric'],
            
            // Rebate & Invoicing
            'original_invoice_value' => ['nullable', 'numeric', 'min:0'],
            'note_type' => ['nullable', 'string', 'in:debit,credit'],
            'manual_adjustment' => ['nullable', 'numeric'],
            'manual_adjustment_reason' => ['nullable', 'string'],
            
            // Taxes
            'is_interstate' => ['nullable', 'boolean'],
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            
            'remarks' => ['nullable', 'string'],
        ];
    }
}
