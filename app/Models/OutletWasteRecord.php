<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletWasteRecord extends Model
{
    protected $fillable = [
        'waste_number', 'request_key', 'warehouse_id', 'cashier_shift_id', 'product_id', 'unit_id',
        'quantity', 'unit_cost_snapshot', 'total_cost', 'reason', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost_snapshot' => 'integer', 'total_cost' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
