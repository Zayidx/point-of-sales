<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletStockReturnItem extends Model
{
    protected $fillable = [
        'outlet_stock_return_id', 'product_id', 'quantity_requested', 'quantity_received', 'unit_cost_snapshot',
    ];

    protected function casts(): array
    {
        return ['quantity_requested' => 'integer', 'quantity_received' => 'integer', 'unit_cost_snapshot' => 'integer'];
    }

    public function stockReturn(): BelongsTo
    {
        return $this->belongsTo(OutletStockReturn::class, 'outlet_stock_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
