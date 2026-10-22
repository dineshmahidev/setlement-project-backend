<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('debit_note') ? $this->route('debit_note')->id : null;

        return [
            'debit_note_number' => ['nullable', 'string', 'max:50', 'unique:debit_notes,debit_note_number,' . $id],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'settlement_id' => ['nullable', 'exists:settlements,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'invoice_date' => ['nullable', 'date'],
            'lot_number' => ['nullable', 'string', 'max:50'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:100'],
            'transaction_type' => ['required', 'in:debit,credit'],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'is_interstate' => ['nullable', 'boolean'],
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.particulars' => ['required_with:items', 'string'],
            'items.*.amount' => ['required_with:items', 'numeric'],
        ];
    }
}
