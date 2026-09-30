<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashierShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shift = $this->route('cashierShift');
        $stockCountRule = $shift?->warehouse_id ? 'required' : 'sometimes';
        $hasOpeningIngredients = $shift?->openingItems()->where('item_type', 'ingredient')->exists() ?? false;

        return [
            'actual_cash' => ['required', 'integer', 'min:0'],
            'close_notes' => ['nullable', 'string', 'max:1000'],
            'closing_stock' => [$stockCountRule, 'array'],
            'closing_stock.*.product_id' => ['required_with:closing_stock', 'integer', 'exists:products,id'],
            'closing_stock.*.actual_stock' => ['required_with:closing_stock', 'integer', 'min:0'],
            'closing_ingredients' => [$hasOpeningIngredients ? 'required' : 'sometimes', 'array'],
            'closing_ingredients.*.opening_item_id' => ['required_with:closing_ingredients', 'integer', 'exists:cashier_shift_opening_items,id'],
            'closing_ingredients.*.actual_quantity' => ['required_with:closing_ingredients', 'numeric', 'decimal:0,4', 'min:0'],
        ];
    }
}
