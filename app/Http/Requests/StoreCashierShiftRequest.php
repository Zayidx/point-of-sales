<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashierShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'opening_items' => ['sometimes', 'array', 'min:1'],
            'opening_items.*.item_type' => ['required', 'in:product,ingredient'],
            'opening_items.*.item_id' => ['required', 'integer', 'min:1'],
            'opening_items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
        ];
    }
}
