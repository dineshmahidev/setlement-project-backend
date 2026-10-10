<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('invoice') ? $this->route('invoice')->id : null;

        return [
            'invoice_number' => ['nullable', 'string', 'max:50', 'unique:invoices,invoice_number,' . $id],
            'invoice_date' => ['required', 'date'],
            'branch_id' => ['required', 'exists:branches,id'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'lot_number' => ['nullable', 'string', 'max:50'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'net_weight_mt' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'is_interstate' => ['nullable', 'boolean'],
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
