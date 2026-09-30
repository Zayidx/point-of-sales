<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierShiftStockCount extends Model
{
    protected $fillable = ['cashier_shift_id', 'product_id', 'expected_stock', 'actual_stock', 'variance'];

    protected function casts(): array
    {
        return ['expected_stock' => 'integer', 'actual_stock' => 'integer', 'variance' => 'integer'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
