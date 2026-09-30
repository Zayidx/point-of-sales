<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    protected $fillable = [
        'balance_key', 'location_type', 'warehouse_id', 'outlet_id',
        'item_type', 'item_id', 'quantity', 'average_unit_cost', 'inventory_value',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'quantity' => 'decimal:4',
            'average_unit_cost' => 'decimal:2',
            'inventory_value' => 'decimal:2',
        ];
    }
}
