<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    protected $fillable = [
        'order_number', 'production_request_id', 'warehouse_id', 'product_id', 'recipe_version_id',
        'planned_output', 'actual_output', 'actual_material_cost', 'actual_unit_cost', 'status',
        'started_by', 'completed_by', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_output' => 'decimal:4',
            'actual_output' => 'decimal:4',
            'actual_material_cost' => 'decimal:2',
            'actual_unit_cost' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductionRequest::class, 'production_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
