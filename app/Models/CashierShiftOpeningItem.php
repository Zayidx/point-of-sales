<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierShiftOpeningItem extends Model
{
    protected $fillable = [
        'cashier_shift_id', 'item_type', 'product_id', 'ingredient_id', 'unit_id', 'quantity', 'closing_quantity', 'unit_cost',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'closing_quantity' => 'decimal:4', 'unit_cost' => 'decimal:2'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
