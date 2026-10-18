<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('product') ? $this->route('product')->id : null;

        return [
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:50', 'unique:products,product_code,' . $id],
            'product_type' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:20'],
            'default_rate' => ['nullable', 'numeric', 'min:0'],
            'default_cgst' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_sgst' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_igst' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
