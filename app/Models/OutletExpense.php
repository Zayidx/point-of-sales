<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletExpense extends Model
{
    protected $fillable = [
        'expense_number', 'request_key', 'warehouse_id', 'cashier_shift_id', 'expense_category_id',
        'item_name', 'quantity', 'unit_price', 'total', 'payment_source', 'notes', 'receipt_path',
        'cash_movement_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'integer', 'total' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
