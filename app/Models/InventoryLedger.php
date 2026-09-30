<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLedger extends Model
{
    public const MOVEMENT_TYPES = [
        'opening_stock', 'purchase_receipt', 'production_consumption', 'production_output',
        'warehouse_to_outlet', 'outlet_to_warehouse', 'sale', 'waste', 'stock_adjustment', 'qc_reject',
    ];

    protected $fillable = [
        'reference_number', 'idempotency_key', 'location_type', 'warehouse_id', 'outlet_id',
        'item_type', 'item_id', 'ingredient_id', 'product_id', 'movement_type', 'quantity',
        'unit_id', 'unit_cost', 'total_cost', 'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'reference_id' => 'integer',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }
}
